<?php

use CRM_Paymentprocessingcore_BAO_PaymentWebhook as PaymentWebhook;
use CRM_Paymentprocessingcore_BAO_PaymentAttempt as PaymentAttempt;
use Civi\Api4\Contact;
use Civi\Api4\Contribution;

/**
 * Tests for CRM_Paymentprocessingcore_BAO_PaymentWebhook.
 *
 * @group headless
 */
class CRM_Paymentprocessingcore_BAO_PaymentWebhookTest extends BaseHeadlessTest {

  /**
   * @var int
   */
  private $contactId;

  /**
   * @var int
   */
  private $contributionId;

  /**
   * @var int
   */
  private $attemptId;

  /**
   * Set up test fixtures.
   */
  public function setUp(): void {
    parent::setUp();

    // Create test contact
    $this->contactId = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Test')
      ->addValue('last_name', 'Webhook')
      ->execute()
      ->first()['id'];

    // Create test contribution
    $this->contributionId = Contribution::create(FALSE)
      ->addValue('contact_id', $this->contactId)
      ->addValue('financial_type_id:name', 'Donation')
      ->addValue('total_amount', 50.00)
      ->addValue('currency', 'GBP')
      ->addValue('contribution_status_id:name', 'Pending')
      ->execute()
      ->first()['id'];

    // Create test payment attempt
    $attempt = PaymentAttempt::create([
      'contribution_id' => $this->contributionId,
      'contact_id' => $this->contactId,
      'processor_type' => 'stripe',
      'status' => 'pending',
    ]);
    $this->assertNotNull($attempt);
    $this->attemptId = intval($attempt->id);
  }

  /**
   * Tests creating a webhook record.
   */
  public function testCreate() {
    $params = [
      'event_id' => 'evt_test_123',
      'processor_type' => 'stripe',
      'event_type' => 'checkout.session.completed',
      'payment_attempt_id' => $this->attemptId,
      'status' => 'new',
    ];

    $webhook = PaymentWebhook::create($params);

    $this->assertNotNull($webhook->id);
    foreach ($params as $key => $value) {
      $this->assertEquals($value, $webhook->{$key}, "Field {$key} should match");
    }
  }

  /**
   * Tests finding webhook by event ID.
   */
  public function testFindByEventId() {
    $eventId = 'evt_test_unique_456';

    // Create webhook
    $params = [
      'event_id' => $eventId,
      'processor_type' => 'stripe',
      'event_type' => 'payment_intent.succeeded',
      'payment_attempt_id' => $this->attemptId,
      'status' => 'new',
    ];

    PaymentWebhook::create($params);

    // Find by event ID
    $found = PaymentWebhook::findByEventId($eventId);

    $this->assertNotNull($found);
    $this->assertEquals($eventId, $found['event_id']);
    $this->assertEquals('stripe', $found['processor_type']);
  }

  /**
   * Tests finding webhook returns NULL when not found.
   */
  public function testFindByEventIdNotFound() {
    $found = PaymentWebhook::findByEventId('evt_nonexistent');

    $this->assertNull($found);
  }

  /**
   * Tests isProcessed returns TRUE for processed events.
   */
  public function testIsProcessedReturnsTrueForProcessedEvent() {
    $eventId = 'evt_test_processed_789';

    // Create processed webhook
    PaymentWebhook::create([
      'event_id' => $eventId,
      'processor_type' => 'stripe',
      'event_type' => 'checkout.session.completed',
      'status' => 'processed',
      'result' => 'applied',
    ]);

    $isProcessed = PaymentWebhook::isProcessed($eventId);

    $this->assertTrue($isProcessed);
  }

  /**
   * Tests isProcessed returns TRUE for events currently processing.
   */
  public function testIsProcessedReturnsTrueForProcessingEvent() {
    $eventId = 'evt_test_processing_101';

    // Create processing webhook
    PaymentWebhook::create([
      'event_id' => $eventId,
      'processor_type' => 'stripe',
      'event_type' => 'checkout.session.completed',
      'status' => 'processing',
    ]);

    $isProcessed = PaymentWebhook::isProcessed($eventId);

    $this->assertTrue($isProcessed);
  }

  /**
   * Tests isProcessed returns FALSE for new events.
   */
  public function testIsProcessedReturnsFalseForNewEvent() {
    $eventId = 'evt_test_new_202';

    // Create new webhook
    PaymentWebhook::create([
      'event_id' => $eventId,
      'processor_type' => 'stripe',
      'event_type' => 'checkout.session.completed',
      'status' => 'new',
    ]);

    $isProcessed = PaymentWebhook::isProcessed($eventId);

    $this->assertFalse($isProcessed);
  }

  /**
   * Tests isProcessed returns FALSE for error events.
   */
  public function testIsProcessedReturnsFalseForErrorEvent() {
    $eventId = 'evt_test_error_303';

    // Create error webhook
    PaymentWebhook::create([
      'event_id' => $eventId,
      'processor_type' => 'stripe',
      'event_type' => 'checkout.session.completed',
      'status' => 'error',
      'error_log' => 'Test error',
    ]);

    $isProcessed = PaymentWebhook::isProcessed($eventId);

    $this->assertFalse($isProcessed);
  }

  /**
   * Tests isProcessed returns FALSE for non-existent events.
   */
  public function testIsProcessedReturnsFalseForNonExistentEvent() {
    $isProcessed = PaymentWebhook::isProcessed('evt_nonexistent_404');

    $this->assertFalse($isProcessed);
  }

  /**
   * Tests getStatuses returns correct status options.
   */
  public function testGetStatuses() {
    $statuses = PaymentWebhook::getStatuses();

    $this->assertIsArray($statuses);
    $this->assertArrayHasKey('new', $statuses);
    $this->assertArrayHasKey('processing', $statuses);
    $this->assertArrayHasKey('processed', $statuses);
    $this->assertArrayHasKey('error', $statuses);

    $this->assertEquals('New', $statuses['new']);
    $this->assertEquals('Processing', $statuses['processing']);
    $this->assertEquals('Processed', $statuses['processed']);
    $this->assertEquals('Error', $statuses['error']);
  }

  /**
   * Tests updating an existing webhook.
   */
  public function testUpdate() {
    // Create initial webhook
    $params = [
      'event_id' => 'evt_test_update_505',
      'processor_type' => 'stripe',
      'event_type' => 'checkout.session.completed',
      'status' => 'new',
    ];

    $webhook = PaymentWebhook::create($params);
    $webhookId = $webhook->id;

    // Update to processed
    $updateParams = [
      'id' => $webhookId,
      'status' => 'processed',
      'result' => 'applied',
      'processed_at' => date('Y-m-d H:i:s'),
    ];

    $updated = PaymentWebhook::create($updateParams);

    $this->assertEquals($webhookId, $updated->id);
    $this->assertEquals('processed', $updated->status);
    $this->assertEquals('applied', $updated->result);
    $this->assertNotNull($updated->processed_at);
  }

  /**
   * Tests unique constraint on event_id.
   */
  public function testUniqueEventIdConstraint() {
    $eventId = 'evt_test_duplicate_606';

    // Create first webhook
    PaymentWebhook::create([
      'event_id' => $eventId,
      'processor_type' => 'stripe',
      'event_type' => 'checkout.session.completed',
      'status' => 'new',
    ]);

    // Try to create duplicate - should fail
    $this->expectException(\Exception::class);

    PaymentWebhook::create([
      'event_id' => $eventId,
      'processor_type' => 'stripe',
      'event_type' => 'checkout.session.completed',
      'status' => 'new',
    ]);
  }

  /**
   * Tests webhook without payment_attempt_id (not all webhooks link to attempts).
   */
  public function testCreateWithoutPaymentAttempt() {
    $params = [
      'event_id' => 'evt_test_no_attempt_707',
      'processor_type' => 'stripe',
      'event_type' => 'account.updated',
      'status' => 'new',
    ];

    $webhook = PaymentWebhook::create($params);

    $this->assertNotNull($webhook->id);
    $this->assertEquals('evt_test_no_attempt_707', $webhook->event_id);
    $this->assertNull($webhook->payment_attempt_id);
  }

  /**
   * Tests different processor types.
   */
  public function testDifferentProcessorTypes() {
    $processors = [
      ['type' => 'stripe', 'event' => 'evt_stripe_808'],
      ['type' => 'gocardless', 'event' => 'evt_gc_809'],
      ['type' => 'itas', 'event' => 'evt_itas_810'],
    ];

    foreach ($processors as $processor) {
      $webhook = PaymentWebhook::create([
        'event_id' => $processor['event'],
        'processor_type' => $processor['type'],
        'event_type' => 'payment.succeeded',
        'status' => 'new',
      ]);

      $this->assertEquals($processor['type'], $webhook->processor_type);
      $this->assertEquals($processor['event'], $webhook->event_id);
    }
  }

  /**
   * Tests all valid statuses.
   */
  public function testAllStatuses() {
    $statuses = ['new', 'processing', 'processed', 'error'];

    foreach ($statuses as $index => $status) {
      $webhook = PaymentWebhook::create([
        'event_id' => "evt_test_status_{$index}_911",
        'processor_type' => 'stripe',
        'event_type' => 'checkout.session.completed',
        'status' => $status,
      ]);

      $this->assertEquals($status, $webhook->status);
    }
  }

  /**
   * Tests updateStatusAtomic sets processing_started_at when status becomes processing.
   */
  public function testUpdateStatusAtomicSetsProcessingStartedAt(): void {
    // Create webhook in 'new' status
    $webhook = PaymentWebhook::create([
      'event_id' => 'evt_test_atomic_1001',
      'processor_type' => 'stripe',
      'event_type' => 'payment_intent.succeeded',
      'status' => 'new',
      'attempts' => 0,
    ]);

    // Verify webhook was created
    $this->assertNotNull($webhook);
    $this->assertNotNull($webhook->id, 'Webhook should be created');

    // Verify initial status
    $created = \Civi\Api4\PaymentWebhook::get(FALSE)
      ->addWhere('id', '=', $webhook->id)
      ->execute()
      ->first();

    $this->assertNotNull($created);
    $this->assertArrayHasKey('status', $created);
    $this->assertEquals('new', $created['status'], 'Initial status should be new');

    // Atomically update to 'processing'
    $updated = PaymentWebhook::updateStatusAtomic((int) $webhook->id, 'new', 'processing');

    $this->assertTrue($updated, 'Status should be updated atomically');

    // Verify processing_started_at was set
    $webhookData = \Civi\Api4\PaymentWebhook::get(FALSE)
      ->addWhere('id', '=', $webhook->id)
      ->execute()
      ->first();

    $this->assertNotNull($webhookData);
    $this->assertArrayHasKey('status', $webhookData);
    $this->assertArrayHasKey('processing_started_at', $webhookData);
    $this->assertEquals('processing', $webhookData['status']);
    $this->assertNotNull($webhookData['processing_started_at'], 'processing_started_at should be set');
  }

  /**
   * Tests updateStatusAtomic does not set processing_started_at for other statuses.
   */
  public function testUpdateStatusAtomicDoesNotSetTimestampForOtherStatuses(): void {
    // Create webhook in 'processing' status
    $webhook = PaymentWebhook::create([
      'event_id' => 'evt_test_atomic_1002',
      'processor_type' => 'stripe',
      'event_type' => 'payment_intent.succeeded',
      'status' => 'processing',
      'attempts' => 0,
      'processing_started_at' => date('Y-m-d H:i:s', strtotime('-10 minutes')),
    ]);

    $this->assertNotNull($webhook);
    $this->assertNotNull($webhook->id);
    $this->assertNotNull($webhook->processing_started_at);
    $originalTimestamp = $webhook->processing_started_at;

    // Update to 'processed' (not 'processing')
    $updated = PaymentWebhook::updateStatusAtomic((int) $webhook->id, 'processing', 'processed');

    $this->assertTrue($updated);

    // Verify processing_started_at wasn't changed
    $webhookData = \Civi\Api4\PaymentWebhook::get(FALSE)
      ->addWhere('id', '=', $webhook->id)
      ->execute()
      ->first();

    $this->assertNotNull($webhookData);
    $this->assertArrayHasKey('status', $webhookData);
    $this->assertArrayHasKey('processing_started_at', $webhookData);
    $this->assertEquals('processed', $webhookData['status']);
    // Timestamp shouldn't change when updating to non-processing status
    $this->assertNotNull($webhookData['processing_started_at']);
    // Compare timestamps (allow for format differences)
    $originalTime = strtotime($originalTimestamp);
    $currentTime = strtotime((string) $webhookData['processing_started_at']);
    $this->assertEquals($originalTime, $currentTime, 'Timestamp should not change');
  }

  /**
   * Tests getStuckWebhooks finds webhooks based on processing_started_at.
   */
  public function testGetStuckWebhooksFindsCorrectRecords(): void {
    // Create webhook stuck in processing (processing_started_at > 30 min ago)
    $stuckWebhook = PaymentWebhook::create([
      'event_id' => 'evt_test_stuck_1003',
      'processor_type' => 'stripe',
      'event_type' => 'payment_intent.succeeded',
      'status' => 'processing',
      'attempts' => 0,
      'processing_started_at' => date('Y-m-d H:i:s', strtotime('-35 minutes')),
    ]);

    // Create webhook in processing but not stuck (processing_started_at < 30 min ago)
    $notStuckWebhook = PaymentWebhook::create([
      'event_id' => 'evt_test_not_stuck_1004',
      'processor_type' => 'stripe',
      'event_type' => 'payment_intent.succeeded',
      'status' => 'processing',
      'attempts' => 0,
      'processing_started_at' => date('Y-m-d H:i:s', strtotime('-10 minutes')),
    ]);

    $this->assertNotNull($stuckWebhook);
    $this->assertNotNull($stuckWebhook->id);
    $this->assertNotNull($notStuckWebhook);
    $this->assertNotNull($notStuckWebhook->id);

    // Get stuck webhooks with 30 min timeout
    $stuckResults = PaymentWebhook::getStuckWebhooks(30);

    $this->assertCount(1, $stuckResults, 'Should find 1 stuck webhook');
    $this->assertEquals($stuckWebhook->id, $stuckResults[0]['id']);
    $this->assertArrayHasKey('attempts', $stuckResults[0]);
    $this->assertArrayHasKey('processor_type', $stuckResults[0]);
    $this->assertEquals('stripe', $stuckResults[0]['processor_type']);
  }

  /**
   * Tests getStuckWebhooks does not return webhooks with NULL processing_started_at.
   */
  public function testGetStuckWebhooksIgnoresNullProcessingStartedAt(): void {
    // Create webhook in processing but with NULL processing_started_at
    $webhook = PaymentWebhook::create([
      'event_id' => 'evt_test_null_1005',
      'processor_type' => 'stripe',
      'event_type' => 'payment_intent.succeeded',
      'status' => 'processing',
      'attempts' => 0,
    ]);

    $this->assertNotNull($webhook);
    $this->assertNotNull($webhook->id);

    // Get stuck webhooks
    $stuckResults = PaymentWebhook::getStuckWebhooks(30);

    $this->assertEmpty($stuckResults, 'Webhook with NULL timestamp should not be found');

    // Verify webhook is still processing
    $webhookData = \Civi\Api4\PaymentWebhook::get(FALSE)
      ->addWhere('id', '=', $webhook->id)
      ->execute()
      ->first();

    $this->assertEquals('processing', $webhookData['status']);
  }

  /**
   * Tests getStuckWebhooks uses 1 day default timeout.
   */
  public function testGetStuckWebhooksDefaultTimeoutIsOneDay(): void {
    // Create webhook stuck for 2 days (should be found)
    $oldStuck = PaymentWebhook::create([
      'event_id' => 'evt_test_old_stuck_1006',
      'processor_type' => 'stripe',
      'event_type' => 'payment_intent.succeeded',
      'status' => 'processing',
      'attempts' => 0,
      'processing_started_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
    ]);

    // Create webhook stuck for only 1 hour (should NOT be found with default)
    $recentStuck = PaymentWebhook::create([
      'event_id' => 'evt_test_recent_stuck_1007',
      'processor_type' => 'stripe',
      'event_type' => 'payment_intent.succeeded',
      'status' => 'processing',
      'attempts' => 0,
      'processing_started_at' => date('Y-m-d H:i:s', strtotime('-1 hour')),
    ]);

    $this->assertNotNull($oldStuck->id);
    $this->assertNotNull($recentStuck->id);

    // Use default timeout (1 day)
    $stuckResults = PaymentWebhook::getStuckWebhooks();

    $this->assertCount(1, $stuckResults, 'Only webhook stuck > 1 day should be found');
    $this->assertEquals($oldStuck->id, $stuckResults[0]['id']);
  }

  /**
   * Tests batchResetStuckToNew increments attempts and sets status to new.
   */
  public function testBatchResetStuckToNewIncrementsAttempts(): void {
    $webhook = PaymentWebhook::create([
      'event_id' => 'evt_test_batch_reset_1008',
      'processor_type' => 'stripe',
      'event_type' => 'payment_intent.succeeded',
      'status' => 'processing',
      'attempts' => 1,
      'processing_started_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
    ]);

    $this->assertNotNull($webhook->id);

    PaymentWebhook::batchResetStuckToNew(
      [(int) $webhook->id],
      'Test reset'
    );

    $webhookData = \Civi\Api4\PaymentWebhook::get(FALSE)
      ->addWhere('id', '=', $webhook->id)
      ->execute()
      ->first();

    $this->assertEquals('new', $webhookData['status']);
    $this->assertEquals(2, $webhookData['attempts']);
    $this->assertEquals('Test reset', $webhookData['error_log']);
  }

  /**
   * Tests batchMarkStuckAsPermanentError sets correct status and fields.
   */
  public function testBatchMarkStuckAsPermanentError(): void {
    $webhook = PaymentWebhook::create([
      'event_id' => 'evt_test_batch_perm_1009',
      'processor_type' => 'stripe',
      'event_type' => 'payment_intent.succeeded',
      'status' => 'processing',
      'attempts' => 2,
      'processing_started_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
    ]);

    $this->assertNotNull($webhook->id);

    PaymentWebhook::batchMarkStuckAsPermanentError(
      [(int) $webhook->id],
      'Max retries exceeded'
    );

    $webhookData = \Civi\Api4\PaymentWebhook::get(FALSE)
      ->addWhere('id', '=', $webhook->id)
      ->execute()
      ->first();

    $this->assertEquals('permanent_error', $webhookData['status']);
    $this->assertEquals('error', $webhookData['result']);
    $this->assertEquals(3, $webhookData['attempts']);
    $this->assertNotNull($webhookData['processed_at']);
    $this->assertEquals('Max retries exceeded', $webhookData['error_log']);
  }

  /**
   * Tests batchResetStuckToNew only updates webhooks in processing status.
   */
  public function testBatchResetStuckToNewOnlyUpdatesProcessingStatus(): void {
    // Create a webhook already in 'new' status (should not be affected)
    $newWebhook = PaymentWebhook::create([
      'event_id' => 'evt_test_guard_1010',
      'processor_type' => 'stripe',
      'event_type' => 'payment_intent.succeeded',
      'status' => 'new',
      'attempts' => 0,
    ]);

    $this->assertNotNull($newWebhook->id);

    PaymentWebhook::batchResetStuckToNew(
      [(int) $newWebhook->id],
      'Should not update'
    );

    $webhookData = \Civi\Api4\PaymentWebhook::get(FALSE)
      ->addWhere('id', '=', $newWebhook->id)
      ->execute()
      ->first();

    // Attempts should NOT have been incremented
    $this->assertEquals(0, $webhookData['attempts']);
    $this->assertEquals('new', $webhookData['status']);
  }

  /**
   * Tests getOrphanedNewWebhooks excludes retry-flow webhooks.
   */
  public function testGetOrphanedNewWebhooksExcludesRetryFlow(): void {
    // Orphan from stuck recovery: new, attempts > 0, no next_retry_at,
    // has processing_started_at (was previously in 'processing')
    $orphan = PaymentWebhook::create([
      'event_id' => 'evt_test_orphan_1011',
      'processor_type' => 'stripe',
      'event_type' => 'payment_intent.succeeded',
      'status' => 'new',
      'attempts' => 1,
      'processing_started_at' => date('Y-m-d H:i:s', strtotime('-2 hours')),
    ]);

    // Retry-flow webhook: new, attempts > 0, HAS next_retry_at
    $retryFlow = PaymentWebhook::create([
      'event_id' => 'evt_test_retry_1012',
      'processor_type' => 'stripe',
      'event_type' => 'payment_intent.succeeded',
      'status' => 'new',
      'attempts' => 1,
      'next_retry_at' => date('Y-m-d H:i:s', strtotime('+10 minutes')),
    ]);

    // Fresh webhook: new, attempts = 0 (should not match)
    $fresh = PaymentWebhook::create([
      'event_id' => 'evt_test_fresh_1013',
      'processor_type' => 'stripe',
      'event_type' => 'payment_intent.succeeded',
      'status' => 'new',
      'attempts' => 0,
    ]);

    $this->assertNotNull($orphan->id);
    $this->assertNotNull($retryFlow->id);
    $this->assertNotNull($fresh->id);

    $results = PaymentWebhook::getOrphanedNewWebhooks('stripe');

    $resultIds = array_column($results, 'id');
    $this->assertContains($orphan->id, $resultIds, 'Orphan should be found');
    $this->assertNotContains($retryFlow->id, $resultIds, 'Retry-flow webhook should be excluded');
    $this->assertNotContains($fresh->id, $resultIds, 'Fresh webhook should be excluded');
  }

  /**
   * Tests batchResetStuckToNew returns actual affected row count.
   */
  public function testBatchResetStuckToNewReturnsAffectedCount(): void {
    $processing = PaymentWebhook::create([
      'event_id' => 'evt_test_affected_1014',
      'processor_type' => 'stripe',
      'event_type' => 'payment_intent.succeeded',
      'status' => 'processing',
      'attempts' => 0,
      'processing_started_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
    ]);

    $notProcessing = PaymentWebhook::create([
      'event_id' => 'evt_test_affected_1015',
      'processor_type' => 'stripe',
      'event_type' => 'payment_intent.succeeded',
      'status' => 'new',
      'attempts' => 0,
    ]);

    $this->assertNotNull($processing->id);
    $this->assertNotNull($notProcessing->id);

    $affected = PaymentWebhook::batchResetStuckToNew(
      [(int) $processing->id, (int) $notProcessing->id],
      'Test reset'
    );

    $this->assertEquals(1, $affected, 'Only the processing webhook should be affected');
  }

}
