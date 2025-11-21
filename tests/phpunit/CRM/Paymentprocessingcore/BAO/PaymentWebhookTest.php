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
    $this->attemptId = $attempt->id;
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

}
