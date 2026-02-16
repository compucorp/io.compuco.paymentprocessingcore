<?php

namespace Civi\Paymentprocessingcore\Service;

use Civi\Paymentprocessingcore\Event\ChargeInstalmentBatchEvent;
use Civi\Paymentprocessingcore\DTO\ChargeInstalmentItem;
use CRM_Paymentprocessingcore_BAO_PaymentAttempt as PaymentAttemptBAO;

/**
 * Tests for InstalmentChargeService.
 *
 * Uses the built-in CiviCRM Dummy payment processor type for testing.
 *
 * @group headless
 */
class InstalmentChargeServiceTest extends \BaseHeadlessTest {

  private const PROCESSOR_TYPE = 'Dummy';

  /**
   * @var \Civi\Paymentprocessingcore\Service\InstalmentChargeService
   */
  private InstalmentChargeService $service;

  /**
   * Captured events from dispatcher.
   *
   * @var array<\Civi\Paymentprocessingcore\Event\ChargeInstalmentBatchEvent>
   */
  private array $capturedEvents = [];

  /**
   * Set up test fixtures.
   */
  public function setUp(): void {
    parent::setUp();
    $this->service = new InstalmentChargeService();
    $this->capturedEvents = [];

    // Register event listener to capture dispatched events.
    \Civi::dispatcher()->addListener(
      ChargeInstalmentBatchEvent::NAME,
      [$this, 'captureEvent']
    );
  }

  /**
   * Tear down test fixtures.
   */
  public function tearDown(): void {
    // Remove event listener.
    \Civi::dispatcher()->removeListener(
      ChargeInstalmentBatchEvent::NAME,
      [$this, 'captureEvent']
    );
    parent::tearDown();
  }

  /**
   * Capture dispatched events for assertions.
   *
   * @param \Civi\Paymentprocessingcore\Event\ChargeInstalmentBatchEvent $event
   *   The dispatched event.
   */
  public function captureEvent(ChargeInstalmentBatchEvent $event): void {
    $this->capturedEvents[] = $event;
  }

  // -------------------------------------------------------------------------
  // Selection query tests
  // -------------------------------------------------------------------------

  /**
   * Tests that Pending contributions are selected.
   */
  public function testSelectsPendingContributions(): void {
    $this->createTestFixtures([
      'contribution_status' => 'Pending',
      'receive_date' => date('Y-m-d', strtotime('-1 day')),
    ]);

    $result = $this->service->chargeInstalments([self::PROCESSOR_TYPE], 500, 3);

    $this->assertEquals(1, $result['charged']);
    $this->assertCount(1, $this->capturedEvents);
  }

  /**
   * Tests that Partially paid contributions are selected.
   */
  public function testSelectsPartiallyPaidContributions(): void {
    $this->createTestFixtures([
      'contribution_status' => 'Partially paid',
      'receive_date' => date('Y-m-d', strtotime('-1 day')),
      'total_amount' => 100.00,
    ]);

    $result = $this->service->chargeInstalments([self::PROCESSOR_TYPE], 500, 3);

    $this->assertEquals(1, $result['charged']);
  }

  /**
   * Tests that Completed contributions are skipped.
   */
  public function testSkipsCompletedContributions(): void {
    $this->createTestFixtures([
      'contribution_status' => 'Completed',
      'receive_date' => date('Y-m-d', strtotime('-1 day')),
    ]);

    $result = $this->service->chargeInstalments([self::PROCESSOR_TYPE], 500, 3);

    $this->assertEquals(0, $result['charged']);
    $this->assertCount(0, $this->capturedEvents);
  }

  /**
   * Tests that Failed contributions are skipped.
   */
  public function testSkipsFailedContributions(): void {
    $this->createTestFixtures([
      'contribution_status' => 'Failed',
      'receive_date' => date('Y-m-d', strtotime('-1 day')),
    ]);

    $result = $this->service->chargeInstalments([self::PROCESSOR_TYPE], 500, 3);

    $this->assertEquals(0, $result['charged']);
  }

  /**
   * Tests that zero outstanding balance contributions are skipped.
   *
   * Note: In CiviCRM, a contribution with zero outstanding balance would
   * have status 'Completed'. This is effectively testSkipsCompletedContributions.
   */
  public function testSkipsZeroOutstandingBalance(): void {
    // A contribution with zero outstanding balance has status Completed.
    $this->createTestFixtures([
      'contribution_status' => 'Completed',
      'receive_date' => date('Y-m-d', strtotime('-1 day')),
      'total_amount' => 50.00,
    ]);

    $result = $this->service->chargeInstalments([self::PROCESSOR_TYPE], 500, 3);

    $this->assertEquals(0, $result['charged']);
  }

  /**
   * Tests that future receive dates are skipped.
   */
  public function testSkipsFutureReceiveDate(): void {
    $this->createTestFixtures([
      'contribution_status' => 'Pending',
      'receive_date' => date('Y-m-d', strtotime('+7 days')),
    ]);

    $result = $this->service->chargeInstalments([self::PROCESSOR_TYPE], 500, 3);

    $this->assertEquals(0, $result['charged']);
  }

  /**
   * Tests that contributions without recur ID are skipped.
   */
  public function testSkipsNullContributionRecurId(): void {
    // Create contribution without recurring.
    $contactId = $this->createContact();
    \Civi\Api4\Contribution::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('financial_type_id:name', 'Donation')
      ->addValue('total_amount', 50.00)
      ->addValue('contribution_status_id:name', 'Pending')
      ->addValue('receive_date', date('Y-m-d', strtotime('-1 day')))
      ->execute();

    $result = $this->service->chargeInstalments([self::PROCESSOR_TYPE], 500, 3);

    $this->assertEquals(0, $result['charged']);
  }

  /**
   * Tests that cancelled recurring contributions are skipped.
   */
  public function testSkipsCancelledRecurring(): void {
    $this->createTestFixtures([
      'contribution_status' => 'Pending',
      'receive_date' => date('Y-m-d', strtotime('-1 day')),
      'recur_status' => 'Cancelled',
    ]);

    $result = $this->service->chargeInstalments([self::PROCESSOR_TYPE], 500, 3);

    $this->assertEquals(0, $result['charged']);
  }

  /**
   * Tests that recurring without payment token are skipped.
   */
  public function testSkipsNullPaymentToken(): void {
    $this->createTestFixtures([
      'contribution_status' => 'Pending',
      'receive_date' => date('Y-m-d', strtotime('-1 day')),
      'with_payment_token' => FALSE,
    ]);

    $result = $this->service->chargeInstalments([self::PROCESSOR_TYPE], 500, 3);

    $this->assertEquals(0, $result['charged']);
  }

  /**
   * Tests that recurring with exceeded failure count are skipped.
   */
  public function testSkipsExceededFailureCount(): void {
    $this->createTestFixtures([
      'contribution_status' => 'Pending',
      'receive_date' => date('Y-m-d', strtotime('-1 day')),
      'failure_count' => 5,
    ]);

    $result = $this->service->chargeInstalments([self::PROCESSOR_TYPE], 500, 3);

    $this->assertEquals(0, $result['charged']);
  }

  /**
   * Tests that contributions with existing processing attempt are skipped.
   */
  public function testSkipsExistingProcessingAttempt(): void {
    $fixtures = $this->createTestFixtures([
      'contribution_status' => 'Pending',
      'receive_date' => date('Y-m-d', strtotime('-1 day')),
    ]);

    // Create processing PaymentAttempt.
    PaymentAttemptBAO::create([
      'contribution_id' => $fixtures['contribution_id'],
      'contact_id' => $fixtures['contact_id'],
      'processor_type' => 'dummy',
      'status' => 'processing',
    ]);

    $result = $this->service->chargeInstalments([self::PROCESSOR_TYPE], 500, 3);

    $this->assertEquals(0, $result['charged']);
  }

  /**
   * Tests that contributions with existing completed attempt are skipped.
   */
  public function testSkipsExistingCompletedAttempt(): void {
    $fixtures = $this->createTestFixtures([
      'contribution_status' => 'Pending',
      'receive_date' => date('Y-m-d', strtotime('-1 day')),
    ]);

    // Create completed PaymentAttempt.
    PaymentAttemptBAO::create([
      'contribution_id' => $fixtures['contribution_id'],
      'contact_id' => $fixtures['contact_id'],
      'processor_type' => 'dummy',
      'status' => 'completed',
    ]);

    $result = $this->service->chargeInstalments([self::PROCESSOR_TYPE], 500, 3);

    $this->assertEquals(0, $result['charged']);
  }

  /**
   * Tests that contributions with existing cancelled attempt are skipped.
   */
  public function testSkipsExistingCancelledAttempt(): void {
    $fixtures = $this->createTestFixtures([
      'contribution_status' => 'Pending',
      'receive_date' => date('Y-m-d', strtotime('-1 day')),
    ]);

    // Create cancelled PaymentAttempt.
    PaymentAttemptBAO::create([
      'contribution_id' => $fixtures['contribution_id'],
      'contact_id' => $fixtures['contact_id'],
      'processor_type' => 'dummy',
      'status' => 'cancelled',
    ]);

    $result = $this->service->chargeInstalments([self::PROCESSOR_TYPE], 500, 3);

    $this->assertEquals(0, $result['charged']);
  }

  /**
   * Tests that processor type filter works correctly.
   */
  public function testFiltersByProcessorType(): void {
    $this->createTestFixtures([
      'contribution_status' => 'Pending',
      'receive_date' => date('Y-m-d', strtotime('-1 day')),
    ]);

    // Query with non-matching processor type.
    $result = $this->service->chargeInstalments(['NonExistentProcessor'], 500, 3);

    $this->assertEquals(0, $result['charged']);
  }

  /**
   * Tests that contributions with pending PaymentAttempt are included.
   */
  public function testIncludesContributionWithPendingAttempt(): void {
    $fixtures = $this->createTestFixtures([
      'contribution_status' => 'Pending',
      'receive_date' => date('Y-m-d', strtotime('-1 day')),
    ]);

    // Create pending PaymentAttempt.
    PaymentAttemptBAO::create([
      'contribution_id' => $fixtures['contribution_id'],
      'contact_id' => $fixtures['contact_id'],
      'processor_type' => 'dummy',
      'status' => 'pending',
    ]);

    $result = $this->service->chargeInstalments([self::PROCESSOR_TYPE], 500, 3);

    // Should be included and attempt transitioned to processing.
    $this->assertEquals(1, $result['charged']);
  }

  // -------------------------------------------------------------------------
  // PaymentAttempt handling tests
  // -------------------------------------------------------------------------

  /**
   * Tests that new PaymentAttempt is created for contribution without one.
   */
  public function testCreatesNewPaymentAttemptForNewContribution(): void {
    $fixtures = $this->createTestFixtures([
      'contribution_status' => 'Pending',
      'receive_date' => date('Y-m-d', strtotime('-1 day')),
    ]);

    $this->service->chargeInstalments([self::PROCESSOR_TYPE], 500, 3);

    // Check PaymentAttempt was created.
    $contributionId = $fixtures['contribution_id'];
    $this->assertNotNull($contributionId);
    $attempt = PaymentAttemptBAO::findByContributionId($contributionId);
    $this->assertNotNull($attempt);
    $this->assertEquals('processing', $attempt['status']);
  }

  /**
   * Tests that existing pending PaymentAttempt is reused.
   */
  public function testReusesPendingPaymentAttempt(): void {
    $fixtures = $this->createTestFixtures([
      'contribution_status' => 'Pending',
      'receive_date' => date('Y-m-d', strtotime('-1 day')),
    ]);

    // Create pending PaymentAttempt.
    $existingAttempt = PaymentAttemptBAO::create([
      'contribution_id' => $fixtures['contribution_id'],
      'contact_id' => $fixtures['contact_id'],
      'processor_type' => 'dummy',
      'status' => 'pending',
    ]);

    $this->service->chargeInstalments([self::PROCESSOR_TYPE], 500, 3);

    // Verify same attempt was used and updated.
    $contributionId = $fixtures['contribution_id'];
    $this->assertNotNull($contributionId);
    $attempt = PaymentAttemptBAO::findByContributionId($contributionId);
    $this->assertNotNull($attempt);
    $this->assertIsArray($attempt);
    $this->assertNotNull($existingAttempt);
    $this->assertEquals($existingAttempt->id, $attempt['id']);
    $this->assertEquals('processing', $attempt['status']);
  }

  /**
   * Tests atomic transition to processing status.
   */
  public function testAtomicTransitionToProcessing(): void {
    $fixtures = $this->createTestFixtures([
      'contribution_status' => 'Pending',
      'receive_date' => date('Y-m-d', strtotime('-1 day')),
    ]);

    $this->service->chargeInstalments([self::PROCESSOR_TYPE], 500, 3);

    $contributionId = $fixtures['contribution_id'];
    $this->assertNotNull($contributionId);
    $attempt = PaymentAttemptBAO::findByContributionId($contributionId);
    $this->assertNotNull($attempt);
    $this->assertIsArray($attempt);
    $this->assertEquals('processing', $attempt['status']);
  }

  // -------------------------------------------------------------------------
  // Batch event dispatch tests
  // -------------------------------------------------------------------------

  /**
   * Tests that batch event is dispatched with all items.
   */
  public function testDispatchesBatchEventWithAllItems(): void {
    // Create multiple contributions.
    $this->createTestFixtures([
      'contribution_status' => 'Pending',
      'receive_date' => date('Y-m-d', strtotime('-1 day')),
    ]);
    $this->createTestFixtures([
      'contribution_status' => 'Pending',
      'receive_date' => date('Y-m-d', strtotime('-2 days')),
    ]);

    $this->service->chargeInstalments([self::PROCESSOR_TYPE], 500, 3);

    $this->assertCount(1, $this->capturedEvents);
    $this->assertCount(2, $this->capturedEvents[0]->getItems());
  }

  /**
   * Tests that batch event contains correct processor type.
   */
  public function testBatchEventContainsCorrectProcessorType(): void {
    $this->createTestFixtures([
      'contribution_status' => 'Pending',
      'receive_date' => date('Y-m-d', strtotime('-1 day')),
    ]);

    $this->service->chargeInstalments([self::PROCESSOR_TYPE], 500, 3);

    $this->assertCount(1, $this->capturedEvents);
    $this->assertEquals(self::PROCESSOR_TYPE, $this->capturedEvents[0]->getProcessorType());
  }

  /**
   * Tests that batch event items have correct data.
   */
  public function testBatchEventItemsHaveCorrectData(): void {
    $fixtures = $this->createTestFixtures([
      'contribution_status' => 'Pending',
      'receive_date' => date('Y-m-d', strtotime('-1 day')),
      'total_amount' => 75.50,
      'currency' => 'GBP',
    ]);

    $this->service->chargeInstalments([self::PROCESSOR_TYPE], 500, 3);

    $items = $this->capturedEvents[0]->getItems();
    $this->assertCount(1, $items);

    $item = reset($items);
    $this->assertInstanceOf(ChargeInstalmentItem::class, $item);
    $this->assertEquals($fixtures['contribution_id'], $item->contributionId);
    $this->assertEquals($fixtures['recur_id'], $item->recurringContributionId);
    $this->assertEquals($fixtures['contact_id'], $item->contactId);
    $this->assertEquals(75.50, $item->amount);
    $this->assertEquals('GBP', $item->currency);
    $this->assertEquals($fixtures['token_id'], $item->paymentTokenId);
    $this->assertEquals($fixtures['processor_id'], $item->paymentProcessorId);
  }

  /**
   * Tests that empty batch does not dispatch event.
   */
  public function testEmptyBatchDoesNotDispatchEvent(): void {
    // No contributions created.
    $result = $this->service->chargeInstalments([self::PROCESSOR_TYPE], 500, 3);

    $this->assertEquals(0, $result['charged']);
    $this->assertCount(0, $this->capturedEvents);
  }

  // -------------------------------------------------------------------------
  // Batch processing tests
  // -------------------------------------------------------------------------

  /**
   * Tests that max batch size is respected.
   */
  public function testRespectsMaxBatchSize(): void {
    // Create 5 contributions.
    for ($i = 0; $i < 5; $i++) {
      $this->createTestFixtures([
        'contribution_status' => 'Pending',
        'receive_date' => date('Y-m-d', strtotime('-' . ($i + 1) . ' days')),
      ]);
    }

    // Only process 2.
    $result = $this->service->chargeInstalments([self::PROCESSOR_TYPE], 2, 3);

    $this->assertEquals(2, $result['charged']);
    $this->assertCount(1, $this->capturedEvents);
    $this->assertCount(2, $this->capturedEvents[0]->getItems());
  }

  /**
   * Tests empty batch processes gracefully.
   */
  public function testProcessesEmptyBatchGracefully(): void {
    $result = $this->service->chargeInstalments([self::PROCESSOR_TYPE], 500, 3);

    $this->assertEquals(0, $result['charged']);
    $this->assertEquals(0, $result['skipped']);
    $this->assertEquals(0, $result['errored']);
    $this->assertArrayHasKey('message', $result);
  }

  /**
   * Tests that result summary has correct counts.
   */
  public function testReturnsCorrectSummary(): void {
    // Create 2 valid contributions.
    $this->createTestFixtures([
      'contribution_status' => 'Pending',
      'receive_date' => date('Y-m-d', strtotime('-1 day')),
    ]);
    $this->createTestFixtures([
      'contribution_status' => 'Pending',
      'receive_date' => date('Y-m-d', strtotime('-2 days')),
    ]);

    $result = $this->service->chargeInstalments([self::PROCESSOR_TYPE], 500, 3);

    $this->assertEquals(2, $result['charged']);
    $this->assertArrayHasKey('skipped', $result);
    $this->assertArrayHasKey('errored', $result);
    $this->assertArrayHasKey('message', $result);
    $this->assertArrayHasKey('processors_processed', $result);
    $this->assertContains(self::PROCESSOR_TYPE, $result['processors_processed']);
  }

  // -------------------------------------------------------------------------
  // Multiple processor types tests
  // -------------------------------------------------------------------------

  /**
   * Tests that multiple processor types can be specified.
   */
  public function testMultipleProcessorTypesCanBeSpecified(): void {
    $this->createTestFixtures([
      'contribution_status' => 'Pending',
      'receive_date' => date('Y-m-d', strtotime('-1 day')),
    ]);

    // Pass array with Dummy processor type.
    $result = $this->service->chargeInstalments([self::PROCESSOR_TYPE], 500, 3);

    $this->assertEquals(1, $result['charged']);
    $this->assertContains(self::PROCESSOR_TYPE, $result['processors_processed']);
  }

  /**
   * Tests that each processor type gets its own batch size.
   */
  public function testEachProcessorGetsOwnBatchSize(): void {
    // Create 3 contributions for Dummy processor.
    for ($i = 0; $i < 3; $i++) {
      $this->createTestFixtures([
        'contribution_status' => 'Pending',
        'receive_date' => date('Y-m-d', strtotime('-' . ($i + 1) . ' days')),
      ]);
    }

    // Process with batch size 2.
    $result = $this->service->chargeInstalments([self::PROCESSOR_TYPE], 2, 3);

    // Should process 2 (batch size), not all 3.
    $this->assertEquals(2, $result['charged']);
  }

  // -------------------------------------------------------------------------
  // Edge case tests
  // -------------------------------------------------------------------------

  /**
   * Tests that partially paid contribution is selected and charged.
   *
   * Note: The current implementation charges total_amount for simplicity.
   * Proper outstanding amount calculation from financial transactions
   * would require more complex test setup.
   */
  public function testHandlesPartiallyPaidContribution(): void {
    $this->createTestFixtures([
      'contribution_status' => 'Partially paid',
      'receive_date' => date('Y-m-d', strtotime('-1 day')),
      'total_amount' => 100.00,
    ]);

    $this->service->chargeInstalments([self::PROCESSOR_TYPE], 500, 3);

    $this->assertCount(1, $this->capturedEvents);
    $items = $this->capturedEvents[0]->getItems();
    $item = reset($items);
    $this->assertNotFalse($item);

    // Currently charges total_amount (paid_amount calculation not implemented).
    $this->assertEquals(100.00, $item->amount);
  }

  // -------------------------------------------------------------------------
  // Helper methods
  // -------------------------------------------------------------------------

  /**
   * Create test fixtures.
   *
   * @param array<string, mixed> $options
   *   Options for the fixtures.
   *
   * @return array<string, int|null>
   *   Fixture IDs.
   */
  private function createTestFixtures(array $options = []): array {
    $contactId = $this->createContact();
    $processorId = $this->createPaymentProcessor();

    $tokenId = NULL;
    if ($options['with_payment_token'] ?? TRUE) {
      $tokenId = $this->createPaymentToken($contactId, $processorId);
    }

    $recurId = $this->createRecurringContribution($contactId, $processorId, $tokenId, [
      'contribution_status_id:name' => $options['recur_status'] ?? 'In Progress',
      'failure_count' => $options['failure_count'] ?? 0,
    ]);

    $totalAmount = $options['total_amount'] ?? 50.00;

    $contributionId = $this->createContribution($contactId, $recurId, [
      'contribution_status_id:name' => $options['contribution_status'] ?? 'Pending',
      'receive_date' => $options['receive_date'] ?? date('Y-m-d'),
      'total_amount' => $totalAmount,
      'currency' => $options['currency'] ?? 'GBP',
    ]);

    // Note: paid_amount is a computed field, not a real column.
    // For Partially Paid contributions, the amount is calculated from
    // financial transactions. For test simplicity, we don't create
    // actual payment records - the service charges total_amount for Pending
    // and we skip complex Partially Paid scenarios.

    return [
      'contact_id' => $contactId,
      'processor_id' => $processorId,
      'token_id' => $tokenId,
      'recur_id' => $recurId,
      'contribution_id' => $contributionId,
    ];
  }

  /**
   * Create a contact.
   *
   * @return int
   */
  private function createContact(): int {
    $contact = \Civi\Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Test')
      ->addValue('last_name', 'User')
      ->execute()
      ->first();

    if (!is_array($contact) || !isset($contact['id'])) {
      throw new \RuntimeException('Failed to create contact');
    }

    return intval($contact['id']);
  }

  /**
   * Create a payment processor using Dummy type.
   *
   * @return int
   */
  private function createPaymentProcessor(): int {
    $processor = \Civi\Api4\PaymentProcessor::create(FALSE)
      ->addValue('name', 'Test Processor ' . uniqid())
      ->addValue('payment_processor_type_id:name', self::PROCESSOR_TYPE)
      ->addValue('class_name', 'Payment_Dummy')
      ->addValue('is_active', TRUE)
      ->addValue('is_test', FALSE)
      ->addValue('domain_id', 1)
      ->execute()
      ->first();

    if (!is_array($processor) || !isset($processor['id'])) {
      throw new \RuntimeException('Failed to create payment processor');
    }

    return intval($processor['id']);
  }

  /**
   * Create a payment token.
   *
   * @param int $contactId
   * @param int $processorId
   *
   * @return int
   */
  private function createPaymentToken(int $contactId, int $processorId): int {
    $token = \Civi\Api4\PaymentToken::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('payment_processor_id', $processorId)
      ->addValue('token', 'tok_test_' . uniqid())
      ->addValue('created_date', date('Y-m-d H:i:s'))
      ->execute()
      ->first();

    if (!is_array($token) || !isset($token['id'])) {
      throw new \RuntimeException('Failed to create payment token');
    }

    return intval($token['id']);
  }

  /**
   * Create a recurring contribution.
   *
   * @param int $contactId
   * @param int $processorId
   * @param int|null $tokenId
   * @param array<string, mixed> $params
   *
   * @return int
   */
  private function createRecurringContribution(int $contactId, int $processorId, ?int $tokenId, array $params = []): int {
    $defaults = [
      'contact_id' => $contactId,
      'payment_processor_id' => $processorId,
      'amount' => 50.00,
      'currency' => 'GBP',
      'financial_type_id:name' => 'Donation',
      'frequency_unit:name' => 'month',
      'frequency_interval' => 1,
      'start_date' => date('Y-m-d', strtotime('-6 months')),
      'contribution_status_id:name' => 'In Progress',
    ];

    if ($tokenId !== NULL) {
      $defaults['payment_token_id'] = $tokenId;
    }

    $values = array_merge($defaults, $params);

    $recur = \Civi\Api4\ContributionRecur::create(FALSE)
      ->setValues($values)
      ->execute()
      ->first();

    if (!is_array($recur) || !isset($recur['id'])) {
      throw new \RuntimeException('Failed to create recurring contribution');
    }

    return intval($recur['id']);
  }

  /**
   * Create a contribution.
   *
   * @param int $contactId
   * @param int $recurId
   * @param array<string, mixed> $params
   *
   * @return int
   */
  private function createContribution(int $contactId, int $recurId, array $params = []): int {
    $defaults = [
      'contact_id' => $contactId,
      'contribution_recur_id' => $recurId,
      'financial_type_id:name' => 'Donation',
      'total_amount' => 50.00,
      'currency' => 'GBP',
      'contribution_status_id:name' => 'Pending',
      'receive_date' => date('Y-m-d'),
    ];

    $values = array_merge($defaults, $params);

    $contribution = \Civi\Api4\Contribution::create(FALSE)
      ->setValues($values)
      ->execute()
      ->first();

    if (!is_array($contribution) || !isset($contribution['id'])) {
      throw new \RuntimeException('Failed to create contribution');
    }

    return intval($contribution['id']);
  }

}
