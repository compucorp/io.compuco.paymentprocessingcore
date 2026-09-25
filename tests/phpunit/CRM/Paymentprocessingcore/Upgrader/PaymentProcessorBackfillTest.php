<?php

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\EntityFinancialTrxn;
use Civi\Api4\PaymentAttempt;
use Civi\Api4\PaymentProcessor;

/**
 * Tests for the payment processor backfill.
 *
 * @group headless
 */
class CRM_Paymentprocessingcore_Upgrader_PaymentProcessorBackfillTest extends BaseHeadlessTest {

  /**
   * @var \CRM_Paymentprocessingcore_Upgrader_PaymentProcessorBackfill
   */
  private $backfill;

  /**
   * @var \Civi\Paymentprocessingcore\Service\ContributionCompletionService
   */
  private $completionService;

  /**
   * @var int
   */
  private $contactId;

  /**
   * Set up test fixtures.
   */
  public function setUp(): void {
    parent::setUp();

    $this->backfill = new CRM_Paymentprocessingcore_Upgrader_PaymentProcessorBackfill();

    /** @var \Civi\Paymentprocessingcore\Service\ContributionCompletionService $completionService */
    $completionService = \Civi::service('paymentprocessingcore.contribution_completion');
    $this->completionService = $completionService;

    $this->contactId = $this->idOf(Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Test')
      ->addValue('last_name', 'Donor')
      ->execute()
      ->first());
  }

  /**
   * Tests a payment left without a processor takes it from the contribution's payment attempt.
   */
  public function testBackfillsTheProcessorFromThePaymentAttempt(): void {
    $processorId = $this->createPaymentProcessor();
    $contributionId = $this->createPaymentMissingItsProcessor($processorId);

    $outstanding = $this->backfill->countTransactionsMissingProcessor();
    $this->assertGreaterThan(0, $outstanding);
    $this->assertEquals($outstanding, $this->backfill->run());
    $this->assertEquals(0, $this->backfill->countTransactionsMissingProcessor());
    $this->assertEquals([$processorId], $this->getRecordedPaymentProcessorIds($contributionId));
  }

  /**
   * Tests nothing is reported as repaired when every payment already has its processor.
   */
  public function testReportsNothingRepairedWhenThereIsNothingToRepair(): void {
    $processorId = $this->createPaymentProcessor();
    $contributionId = $this->createPendingContribution();
    $this->createPaymentAttempt($contributionId, $processorId);
    $this->completionService->complete($contributionId, 'ch_test_already_recorded', NULL, FALSE, $processorId);

    $this->assertEquals(0, $this->backfill->run());
    $this->assertEquals([$processorId], $this->getRecordedPaymentProcessorIds($contributionId));
  }

  /**
   * Tests running the backfill twice does not change anything the second time.
   */
  public function testIsSafeToRunAgain(): void {
    $processorId = $this->createPaymentProcessor();
    $contributionId = $this->createPaymentMissingItsProcessor($processorId);

    $this->assertGreaterThan(0, $this->backfill->run());
    $this->assertEquals(0, $this->backfill->run());
    $this->assertEquals([$processorId], $this->getRecordedPaymentProcessorIds($contributionId));
  }

  /**
   * Tests a payment that already names a processor keeps the one it has.
   */
  public function testLeavesAPaymentThatAlreadyNamesAProcessorAlone(): void {
    $recordedProcessorId = $this->createPaymentProcessor();
    $attemptProcessorId = $this->createPaymentProcessor();
    $contributionId = $this->createPendingContribution();
    $this->completionService->complete($contributionId, 'ch_test_keeps_processor', NULL, FALSE, $recordedProcessorId);
    $this->createPaymentAttempt($contributionId, $attemptProcessorId);

    $this->backfill->run();

    $this->assertEquals([$recordedProcessorId], $this->getRecordedPaymentProcessorIds($contributionId));
  }

  /**
   * Tests a payment with no payment attempt to learn from is left as it is.
   */
  public function testLeavesAPaymentWithNoPaymentAttemptAlone(): void {
    $contributionId = $this->createPendingContribution();
    $this->completionService->complete($contributionId, 'ch_test_no_attempt', NULL, FALSE);

    $this->backfill->run();

    $this->assertEquals([NULL], $this->getRecordedPaymentProcessorIds($contributionId));
  }

  /**
   * Tests attempts recorded without a processor are not used.
   */
  public function testIgnoresPaymentAttemptsThatHaveNoProcessor(): void {
    $contributionId = $this->createPendingContribution();
    $this->completionService->complete($contributionId, 'ch_test_attempt_no_processor', NULL, FALSE);
    $this->createPaymentAttempt($contributionId, NULL);

    $this->backfill->run();

    $this->assertEquals([NULL], $this->getRecordedPaymentProcessorIds($contributionId));
  }

  /**
   * Tests each contribution is repaired with its own processor.
   */
  public function testRepairsEachContributionWithItsOwnProcessor(): void {
    $firstProcessorId = $this->createPaymentProcessor();
    $secondProcessorId = $this->createPaymentProcessor();
    $firstContributionId = $this->createPaymentMissingItsProcessor($firstProcessorId);
    $secondContributionId = $this->createPaymentMissingItsProcessor($secondProcessorId);

    $this->backfill->run();

    $this->assertEquals([$firstProcessorId], $this->getRecordedPaymentProcessorIds($firstContributionId));
    $this->assertEquals([$secondProcessorId], $this->getRecordedPaymentProcessorIds($secondContributionId));
  }

  /**
   * Helper: Complete a contribution the way the extension used to, without a processor.
   *
   * The payment attempt is created after completion so that the attempt cannot be used to resolve
   * the processor while the payment is being recorded, which reproduces the data left behind by
   * the versions this backfill exists to repair.
   *
   * @return int The contribution ID
   */
  private function createPaymentMissingItsProcessor(int $paymentProcessorId): int {
    $contributionId = $this->createPendingContribution();
    $this->completionService->complete($contributionId, 'ch_test_' . uniqid(), NULL, FALSE);
    $this->createPaymentAttempt($contributionId, $paymentProcessorId, 'completed');

    // Guard the fixture: the payment must really be missing its processor.
    $this->assertEquals([NULL], $this->getRecordedPaymentProcessorIds($contributionId));

    return $contributionId;
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
   * Helper: Create a Pending contribution.
   */
  private function createPendingContribution(float $amount = 100.00): int {
    return $this->idOf(Contribution::create(FALSE)
      ->addValue('contact_id', $this->contactId)
      ->addValue('financial_type_id:name', 'Donation')
      ->addValue('total_amount', $amount)
      ->addValue('currency', 'GBP')
      ->addValue('contribution_status_id:name', 'Pending')
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
