<?php

namespace Civi\Paymentprocessingcore\Service;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionPage;
use Civi\Api4\ContributionRecur;
use Civi\Api4\EntityFinancialTrxn;
use Civi\Api4\PaymentAttempt;
use Civi\Api4\PaymentProcessor;
use Civi\Core\Event\GenericHookEvent;
use Civi\Paymentprocessingcore\Exception\ContributionCompletionException;

/**
 * Tests for ContributionCompletionService.
 *
 * @group headless
 */
class ContributionCompletionServiceTest extends \BaseHeadlessTest {

  /**
   * @var \Civi\Paymentprocessingcore\Service\ContributionCompletionService
   */
  private $service;

  /**
   * @var int
   */
  private $contactId;

  /**
   * @var int
   */
  private $contributionPageId;

  /**
   * Workflow names of template mails (receipts) sent during the test.
   *
   * @var array<int, string>
   */
  private array $sentMails = [];

  /**
   * Listener that records each template mail in $sentMails.
   *
   * @var callable
   */
  private $mailSpy;

  /**
   * Set up test fixtures.
   */
  public function setUp(): void {
    parent::setUp();

    // Get service from container
    $this->service = \Civi::service('paymentprocessingcore.contribution_completion');

    // Create test contact (with an email so receipts can be sent)
    $this->contactId = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Test')
      ->addValue('last_name', 'Donor')
      ->addValue('email_primary.email', 'test.donor@example.org')
      ->execute()
      ->first()['id'];

    // Create test contribution page
    $this->contributionPageId = ContributionPage::create(FALSE)
      ->addValue('title', 'Test Contribution Page')
      ->addValue('financial_type_id:name', 'Donation')
      ->addValue('is_email_receipt', TRUE)
      ->execute()
      ->first()['id'];

    $this->sentMails = [];
    $this->mailSpy = function (GenericHookEvent $event): void {
      // alterMailParams hook values are [0 => $params, 1 => $context].
      $hookValues = $event->getHookValues();
      $params = $hookValues[0] ?? NULL;
      $context = $hookValues[1] ?? NULL;
      if ($context !== 'messageTemplate' || !is_array($params)) {
        return;
      }
      $workflow = $params['workflow'] ?? $params['valueName'] ?? NULL;
      if (is_string($workflow)) {
        $this->sentMails[] = $workflow;
      }
    };
    \Civi::dispatcher()->addListener('hook_civicrm_alterMailParams', $this->mailSpy);
  }

  /**
   * Remove the mail spy listener.
   */
  public function tearDown(): void {
    \Civi::dispatcher()->removeListener('hook_civicrm_alterMailParams', $this->mailSpy);
    parent::tearDown();
  }

  /**
   * Tests completing a Pending contribution successfully.
   */
  public function testCompletesPendingContribution(): void {
    $contributionId = $this->createPendingContribution();
    $transactionId = 'ch_test_12345';
    $feeAmount = 2.50;

    $result = $this->service->complete($contributionId, $transactionId, $feeAmount, FALSE);

    $this->assertTrue($result['success']);
    $this->assertEquals($contributionId, $result['contribution_id']);
    $this->assertFalse($result['already_completed']);

    // Verify contribution status updated
    $contribution = Contribution::get(FALSE)
      ->addSelect('contribution_status_id:name', 'trxn_id')
      ->addWhere('id', '=', $contributionId)
      ->execute()
      ->first();

    $this->assertEquals('Completed', $contribution['contribution_status_id:name']);
    $this->assertEquals($transactionId, $contribution['trxn_id']);
  }

  /**
   * Tests idempotency - completing already completed contribution returns success.
   */
  public function testIdempotencyAlreadyCompleted(): void {
    $contributionId = $this->createPendingContribution();
    $transactionId = 'ch_test_67890';

    // Complete first time
    $this->service->complete($contributionId, $transactionId, NULL, FALSE);

    // Complete second time (idempotency check)
    $result = $this->service->complete($contributionId, $transactionId, NULL, FALSE);

    $this->assertTrue($result['success']);
    $this->assertTrue($result['already_completed']);
  }

  /**
   * Tests completing non-Pending contribution throws exception.
   */
  public function testThrowsExceptionForNonPendingContribution(): void {
    $contributionId = $this->createPendingContribution();

    $this->expectException(ContributionCompletionException::class);
    $this->expectExceptionMessage("status is 'Cancelled', expected 'Pending'");

    // Mark as Cancelled first
    Contribution::update(FALSE)
      ->addWhere('id', '=', $contributionId)
      ->addValue('contribution_status_id:name', 'Cancelled')
      ->execute();

    $this->service->complete($contributionId, 'ch_test_cancelled', NULL, FALSE);
  }

  /**
   * Tests completing invalid contribution ID throws exception.
   */
  public function testThrowsExceptionForInvalidContributionId(): void {
    $invalidId = 999999;

    $this->expectException(ContributionCompletionException::class);
    $this->expectExceptionMessage('Contribution not found');

    $this->service->complete($invalidId, 'ch_test_invalid', NULL, FALSE);
  }

  /**
   * Tests fee amount is recorded correctly.
   */
  public function testRecordsFeeAmount(): void {
    $contributionId = $this->createPendingContribution(100.00);
    $feeAmount = 3.20;

    $this->service->complete($contributionId, 'ch_test_fee', $feeAmount, FALSE);

    $contribution = Contribution::get(FALSE)
      ->addSelect('fee_amount', 'net_amount')
      ->addWhere('id', '=', $contributionId)
      ->execute()
      ->first();

    $this->assertEquals($feeAmount, $contribution['fee_amount']);
    // 100.00 - 3.20.
    $this->assertEquals(96.80, $contribution['net_amount']);
  }

  /**
   * Tests the payment processor passed by the caller is recorded on the payment transaction.
   *
   * This is what lets features that refund a payment - Finance Extras in particular - work out
   * which processor to send the refund through.
   */
  public function testRecordsGivenPaymentProcessorOnPaymentTransaction(): void {
    $processorId = $this->createPaymentProcessor();
    $contributionId = $this->createPendingContribution();

    $this->service->complete($contributionId, 'ch_test_given_processor', NULL, FALSE, $processorId);

    $this->assertEquals([$processorId], $this->getRecordedPaymentProcessorIds($contributionId));
  }

  /**
   * Tests the processor is taken from the contribution's payment attempt when the caller omits it.
   */
  public function testResolvesPaymentProcessorFromPaymentAttempt(): void {
    $processorId = $this->createPaymentProcessor();
    $contributionId = $this->createPendingContribution();
    $this->createPaymentAttempt($contributionId, $processorId);

    $this->service->complete($contributionId, 'ch_test_attempt_processor', NULL, FALSE);

    $this->assertEquals([$processorId], $this->getRecordedPaymentProcessorIds($contributionId));
  }

  /**
   * Tests the caller's processor is used even when a payment attempt names a different one.
   */
  public function testGivenPaymentProcessorTakesPrecedenceOverPaymentAttempt(): void {
    $attemptProcessorId = $this->createPaymentProcessor();
    $givenProcessorId = $this->createPaymentProcessor();
    $contributionId = $this->createPendingContribution();
    $this->createPaymentAttempt($contributionId, $attemptProcessorId);

    $this->service->complete($contributionId, 'ch_test_caller_wins', NULL, FALSE, $givenProcessorId);

    $this->assertEquals([$givenProcessorId], $this->getRecordedPaymentProcessorIds($contributionId));
  }

  /**
   * Tests attempts recorded without a processor do not stop the recurring contribution being used.
   */
  public function testFallsBackToRecurringContributionWhenPaymentAttemptHasNoProcessor(): void {
    $processorId = $this->createPaymentProcessor();
    $recurId = $this->createRecurringContribution($processorId);
    $contributionId = $this->createPendingContribution(100.00, NULL, $recurId);
    $this->createPaymentAttempt($contributionId, NULL);

    $this->service->complete($contributionId, 'ch_test_recur_processor', NULL, FALSE);

    $this->assertEquals([$processorId], $this->getRecordedPaymentProcessorIds($contributionId));
  }

  /**
   * Tests the processor is taken from the recurring contribution when there is no payment attempt.
   */
  public function testResolvesPaymentProcessorFromRecurringContribution(): void {
    $processorId = $this->createPaymentProcessor();
    $recurId = $this->createRecurringContribution($processorId);
    $contributionId = $this->createPendingContribution(100.00, NULL, $recurId);

    $this->service->complete($contributionId, 'ch_test_recur_only', NULL, FALSE);

    $this->assertEquals([$processorId], $this->getRecordedPaymentProcessorIds($contributionId));
  }

  /**
   * Tests another contribution's payment attempt is not used.
   */
  public function testIgnoresPaymentAttemptsBelongingToAnotherContribution(): void {
    $processorId = $this->createPaymentProcessor();
    $otherContributionId = $this->createPendingContribution();
    $this->createPaymentAttempt($otherContributionId, $processorId);
    $contributionId = $this->createPendingContribution();

    $this->service->complete($contributionId, 'ch_test_other_contribution', NULL, FALSE);

    $this->assertEquals([NULL], $this->getRecordedPaymentProcessorIds($contributionId));
  }

  /**
   * Tests a contribution still completes when the processor cannot be worked out.
   *
   * Back office payments have neither a payment attempt nor a recurring contribution, and they
   * must not start failing because of this.
   */
  public function testCompletesWithoutAProcessorWhenNoneCanBeResolved(): void {
    $contributionId = $this->createPendingContribution();

    $result = $this->service->complete($contributionId, 'ch_test_no_processor', NULL, FALSE);

    $this->assertTrue($result['success']);
    $this->assertEquals([NULL], $this->getRecordedPaymentProcessorIds($contributionId));

    $contribution = Contribution::get(FALSE)
      ->addSelect('contribution_status_id:name')
      ->addWhere('id', '=', $contributionId)
      ->execute()
      ->first() ?? [];

    $this->assertEquals('Completed', $contribution['contribution_status_id:name']);
  }

  /**
   * Tests the contribution takes its payment instrument from the processor.
   *
   * Core does this whenever a processor is passed to Contribution.completetransaction, so it is a
   * consequence of the fix rather than something this extension asks for. Pinned here so that any
   * future change in that behaviour is noticed.
   */
  public function testContributionTakesItsPaymentInstrumentFromTheProcessor(): void {
    $processorId = $this->createPaymentProcessor();
    $contributionId = $this->createPendingContribution();

    $this->service->complete($contributionId, 'ch_test_instrument', NULL, FALSE, $processorId);

    $processor = PaymentProcessor::get(FALSE)
      ->addSelect('payment_instrument_id')
      ->addWhere('id', '=', $processorId)
      ->execute()
      ->first() ?? [];

    $contribution = Contribution::get(FALSE)
      ->addSelect('payment_instrument_id')
      ->addWhere('id', '=', $contributionId)
      ->execute()
      ->first() ?? [];

    $this->assertEquals($processor['payment_instrument_id'], $contribution['payment_instrument_id']);
  }

  /**
   * Tests receipt is sent for a recurring instalment with no contribution page.
   *
   * Instalments created by the scheduled job only have contribution_recur_id,
   * so the recurring contribution's is_email_receipt setting must be used.
   */
  public function testSendsReceiptWhenRecurringContributionIsSetToReceipt(): void {
    $recurId = $this->createRecurringContribution(NULL, TRUE);
    $contributionId = $this->createPendingContribution(100.00, NULL, $recurId);

    $this->service->complete($contributionId, 'ch_test_recur_receipt');

    $this->assertContributionCompleted($contributionId);
    $this->assertReceiptSent();
  }

  /**
   * Tests no receipt is sent when the recurring contribution disables receipts.
   */
  public function testDoesNotSendReceiptWhenRecurringContributionIsNotSetToReceipt(): void {
    $recurId = $this->createRecurringContribution(NULL, FALSE);
    $contributionId = $this->createPendingContribution(100.00, NULL, $recurId);

    $this->service->complete($contributionId, 'ch_test_recur_no_receipt');

    $this->assertContributionCompleted($contributionId);
    $this->assertNoReceiptSent();
  }

  /**
   * Tests the contribution page setting takes precedence over the recurring one.
   */
  public function testContributionPageSettingTakesPrecedenceOverRecurringContribution(): void {
    $recurId = $this->createRecurringContribution(NULL, FALSE);
    $contributionId = $this->createPendingContribution(100.00, $this->contributionPageId, $recurId);

    $this->service->complete($contributionId, 'ch_test_page_over_recur');

    $this->assertContributionCompleted($contributionId);
    $this->assertReceiptSent();
  }

  /**
   * Tests a page set not to receipt wins over a recur left at the DB default.
   *
   * webform_civicrm creates the recurring contribution without
   * is_email_receipt, so it gets the DB default of 1. The first payment must
   * still follow the page and not send a receipt.
   */
  public function testDoesNotSendReceiptWhenPageIsNotSetToReceiptAndRecurUsesDefault(): void {
    $noReceiptPageId = $this->createContributionPage(FALSE);
    $recurId = $this->createRecurringContribution(NULL);
    $contributionId = $this->createPendingContribution(100.00, $noReceiptPageId, $recurId);

    $this->service->complete($contributionId, 'ch_test_webform_first_payment');

    $this->assertContributionCompleted($contributionId);
    $this->assertNoReceiptSent();
  }

  /**
   * Tests contribution page setting is used for non-recurring contributions.
   */
  public function testSendsReceiptWhenContributionPageIsSetToReceipt(): void {
    $contributionId = $this->createPendingContribution(100.00, $this->contributionPageId);

    $this->service->complete($contributionId, 'ch_test_page_receipt');

    $this->assertContributionCompleted($contributionId);
    $this->assertReceiptSent();
  }

  /**
   * Tests no receipt is sent without a contribution page or recurring record.
   */
  public function testDoesNotSendReceiptWithoutContributionPageOrRecurringContribution(): void {
    $contributionId = $this->createPendingContribution();

    $this->service->complete($contributionId, 'ch_test_no_page_no_recur');

    $this->assertContributionCompleted($contributionId);
    $this->assertNoReceiptSent();
  }

  /**
   * Tests an explicit $sendReceipt overrides the recurring contribution setting.
   */
  public function testExplicitSendReceiptOverridesRecurringContributionSetting(): void {
    $recurId = $this->createRecurringContribution(NULL, TRUE);
    $contributionId = $this->createPendingContribution(100.00, NULL, $recurId);

    $this->service->complete($contributionId, 'ch_test_explicit_no_receipt', NULL, FALSE);

    $this->assertContributionCompleted($contributionId);
    $this->assertNoReceiptSent();
  }

  /**
   * Tests service is accessible via container.
   */
  public function testServiceAccessibleViaContainer(): void {
    $service = \Civi::service('paymentprocessingcore.contribution_completion');

    $this->assertInstanceOf(ContributionCompletionService::class, $service);
  }

  /**
   * Helper: The id of a record the API just returned, as an int.
   *
   * @phpstan-param array<string, mixed>|null $record
   */
  private function idOf(?array $record): int {
    $id = $record['id'] ?? NULL;

    return is_numeric($id) ? (int) $id : 0;
  }

  /**
   * Helper: Create Pending contribution.
   */
  private function createPendingContribution(float $amount = 100.00, ?int $contributionPageId = NULL, ?int $contributionRecurId = NULL): int {
    $params = [
      'contact_id' => $this->contactId,
      'financial_type_id:name' => 'Donation',
      'total_amount' => $amount,
      'currency' => 'GBP',
      'contribution_status_id:name' => 'Pending',
    ];

    if ($contributionPageId !== NULL) {
      $params['contribution_page_id'] = $contributionPageId;
    }

    if ($contributionRecurId !== NULL) {
      $params['contribution_recur_id'] = $contributionRecurId;
    }

    return $this->idOf(Contribution::create(FALSE)
      ->setValues($params)
      ->execute()
      ->first());
  }

  /**
   * Helper: Create a contribution page with the given receipt setting.
   */
  private function createContributionPage(bool $isEmailReceipt): int {
    return $this->idOf(ContributionPage::create(FALSE)
      ->addValue('title', 'Test Contribution Page ' . uniqid())
      ->addValue('financial_type_id:name', 'Donation')
      ->addValue('is_email_receipt', $isEmailReceipt)
      ->execute()
      ->first());
  }

  /**
   * Helper: Create an active payment processor.
   */
  private function createPaymentProcessor(): int {
    return $this->idOf(PaymentProcessor::create(FALSE)
      ->addValue('name', 'Test Processor ' . uniqid())
      ->addValue('payment_processor_type_id:name', 'Dummy')
      ->addValue('class_name', 'Payment_Dummy')
      ->addValue('is_active', TRUE)
      ->addValue('is_test', FALSE)
      ->addValue('domain_id', 1)
      ->execute()
      ->first());
  }

  /**
   * Helper: Create a payment attempt against a contribution.
   */
  private function createPaymentAttempt(int $contributionId, ?int $paymentProcessorId, string $status = 'pending'): int {
    $attempt = PaymentAttempt::create(FALSE)
      ->addValue('contribution_id', $contributionId)
      ->addValue('contact_id', $this->contactId)
      ->addValue('processor_type', 'dummy')
      ->addValue('status', $status);

    if ($paymentProcessorId !== NULL) {
      $attempt->addValue('payment_processor_id', $paymentProcessorId);
    }

    return $this->idOf($attempt->execute()->first());
  }

  /**
   * Helper: Create a recurring contribution.
   *
   * @param int|null $paymentProcessorId
   *   Payment processor to link, if any.
   * @param bool|null $isEmailReceipt
   *   Receipt setting to store, or NULL to leave the default.
   */
  private function createRecurringContribution(?int $paymentProcessorId, ?bool $isEmailReceipt = NULL): int {
    $recur = ContributionRecur::create(FALSE)
      ->addValue('contact_id', $this->contactId)
      ->addValue('amount', 100.00)
      ->addValue('currency', 'GBP')
      ->addValue('frequency_unit:name', 'month')
      ->addValue('frequency_interval', 1)
      ->addValue('contribution_status_id:name', 'Pending');

    if ($paymentProcessorId !== NULL) {
      $recur->addValue('payment_processor_id', $paymentProcessorId);
    }

    if ($isEmailReceipt !== NULL) {
      $recur->addValue('is_email_receipt', $isEmailReceipt);
    }

    return $this->idOf($recur->execute()->first());
  }

  /**
   * Helper: Assert the contribution is Completed.
   */
  private function assertContributionCompleted(int $contributionId): void {
    $contribution = Contribution::get(FALSE)
      ->addSelect('contribution_status_id:name')
      ->addWhere('id', '=', $contributionId)
      ->execute()
      ->first();

    $this->assertIsArray($contribution);
    $this->assertSame('Completed', $contribution['contribution_status_id:name']);
  }

  /**
   * Helper: Assert a contribution receipt was sent.
   */
  private function assertReceiptSent(): void {
    $this->assertNotEmpty(
      $this->getSentContributionReceipts(),
      'Expected a contribution receipt to be sent'
    );
  }

  /**
   * Helper: Assert no contribution receipt was sent.
   */
  private function assertNoReceiptSent(): void {
    $this->assertSame(
      [],
      $this->getSentContributionReceipts(),
      'Expected no contribution receipt to be sent'
    );
  }

  /**
   * Helper: Get sent contribution receipt workflow names.
   *
   * @return array<int, string>
   */
  private function getSentContributionReceipts(): array {
    return array_values(array_filter(
      $this->sentMails,
      static fn (string $workflow): bool => strpos($workflow, 'contribution_') === 0
    ));
  }

  /**
   * Helper: The distinct payment processors recorded across a contribution's payment transactions.
   *
   * Completing a contribution writes several payment transactions, and they all carry the same
   * processor, so the distinct values are what the assertions are about.
   *
   * @return array<int, int|null>
   */
  private function getRecordedPaymentProcessorIds(int $contributionId): array {
    $entityTrxns = EntityFinancialTrxn::get(FALSE)
      ->addSelect('financial_trxn_id.payment_processor_id')
      ->addWhere('entity_table', '=', 'civicrm_contribution')
      ->addWhere('entity_id', '=', $contributionId)
      ->addWhere('financial_trxn_id.is_payment', '=', TRUE)
      ->execute();

    $processorIds = [];
    foreach ($entityTrxns as $entityTrxn) {
      $processorId = is_array($entityTrxn) ? ($entityTrxn['financial_trxn_id.payment_processor_id'] ?? NULL) : NULL;
      $processorIds[] = is_numeric($processorId) ? (int) $processorId : NULL;
    }

    return array_values(array_unique($processorIds, SORT_REGULAR));
  }

}
