<?php

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\EntityFinancialTrxn;
use Civi\Api4\PaymentAttempt;
use Civi\Api4\PaymentProcessor;

/**
 * Tests for the upgrade steps.
 *
 * What each step does is covered by the class it delegates to; these tests are about the steps
 * running that work and reporting success.
 *
 * @group headless
 */
class CRM_Paymentprocessingcore_UpgraderTest extends BaseHeadlessTest {

  /**
   * @var \CRM_Paymentprocessingcore_Upgrader
   */
  private $upgrader;

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

    $this->upgrader = new CRM_Paymentprocessingcore_Upgrader();

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
   * Tests step 1001 backfills the payment processor on a payment recorded without one.
   */
  public function testUpgrade1001BackfillsThePaymentProcessor(): void {
    $processorId = $this->createPaymentProcessor();

    $contributionId = $this->createPendingContribution();
    $this->completionService->complete($contributionId, 'ch_test_' . uniqid(), NULL, FALSE);
    $this->createPaymentAttempt($contributionId, $processorId);
    $this->assertEquals([NULL], $this->getRecordedPaymentProcessorIds($contributionId));

    $this->assertTrue($this->upgrader->upgrade_1001());
    $this->assertEquals([$processorId], $this->getRecordedPaymentProcessorIds($contributionId));
  }

  /**
   * Tests step 1001 succeeds on a site that has nothing to repair.
   */
  public function testUpgrade1001SucceedsWithNothingToRepair(): void {
    $this->assertTrue($this->upgrader->upgrade_1001());
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
  private function createPaymentAttempt(int $contributionId, int $paymentProcessorId): int {
    return $this->idOf(PaymentAttempt::create(FALSE)
      ->addValue('contribution_id', $contributionId)
      ->addValue('contact_id', $this->contactId)
      ->addValue('processor_type', 'dummy')
      ->addValue('status', 'completed')
      ->addValue('payment_processor_id', $paymentProcessorId)
      ->execute()
      ->first());
  }

  /**
   * Helper: The distinct payment processors recorded across a contribution's payment transactions.
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
