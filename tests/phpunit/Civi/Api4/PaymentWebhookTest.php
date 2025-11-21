<?php

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\PaymentAttempt;
use Civi\Api4\PaymentWebhook;

/**
 * PaymentWebhook API Test Case.
 *
 * Tests for the PaymentWebhook Api4 entity.
 *
 * @group headless
 */
class Civi_Api4_PaymentWebhookTest extends BaseHeadlessTest {

  private $contactId;
  private $contributionId;
  private $attemptId;

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
      ->addValue('total_amount', 75.00)
      ->addValue('currency', 'GBP')
      ->addValue('contribution_status_id:name', 'Pending')
      ->execute()
      ->first()['id'];

    // Create test payment attempt
    $this->attemptId = PaymentAttempt::create(FALSE)
      ->addValue('contribution_id', $this->contributionId)
      ->addValue('contact_id', $this->contactId)
      ->addValue('processor_type', 'stripe')
      ->addValue('status', 'pending')
      ->execute()
      ->first()['id'];
  }

  /**
   * Test creating a PaymentWebhook with required fields.
   */
  public function testCreatePaymentWebhookWithRequiredFields() {
    $created = PaymentWebhook::create(FALSE)
      ->addValue('event_id', 'evt_test_123')
      ->addValue('processor_type', 'stripe')
      ->addValue('event_type', 'checkout.session.completed')
      ->execute()
      ->first();

    // Fetch the full record to get default values
    $webhook = PaymentWebhook::get(FALSE)
      ->addWhere('id', '=', $created['id'])
      ->execute()
      ->first();

    $this->assertNotEmpty($webhook['id']);
    $this->assertEquals('evt_test_123', $webhook['event_id']);
    $this->assertEquals('stripe', $webhook['processor_type']);
    $this->assertEquals('checkout.session.completed', $webhook['event_type']);
    $this->assertEquals('new', $webhook['status']);
    $this->assertNotEmpty($webhook['created_date']);
  }

  /**
   * Test creating a PaymentWebhook with all fields.
   */
  public function testCreatePaymentWebhookWithAllFields() {
    $webhook = PaymentWebhook::create(FALSE)
      ->addValue('event_id', 'evt_test_456')
      ->addValue('processor_type', 'stripe')
      ->addValue('event_type', 'payment_intent.succeeded')
      ->addValue('payment_attempt_id', $this->attemptId)
      ->addValue('status', 'processed')
      ->addValue('result', 'applied')
      ->addValue('processed_at', date('Y-m-d H:i:s'))
      ->execute()
      ->first();

    $this->assertNotEmpty($webhook['id']);
    $this->assertEquals('evt_test_456', $webhook['event_id']);
    $this->assertEquals('stripe', $webhook['processor_type']);
    $this->assertEquals('payment_intent.succeeded', $webhook['event_type']);
    $this->assertEquals($this->attemptId, $webhook['payment_attempt_id']);
    $this->assertEquals('processed', $webhook['status']);
    $this->assertEquals('applied', $webhook['result']);
    $this->assertNotEmpty($webhook['processed_at']);
  }

  /**
   * Test that event_id is required.
   */
  public function testCreateWithoutEventIdFails() {
    $this->expectException(\CRM_Core_Exception::class);

    PaymentWebhook::create(FALSE)
      ->addValue('processor_type', 'stripe')
      ->addValue('event_type', 'checkout.session.completed')
      ->execute();
  }

  /**
   * Test that processor_type is required.
   */
  public function testCreateWithoutProcessorTypeFails() {
    $this->expectException(\CRM_Core_Exception::class);

    PaymentWebhook::create(FALSE)
      ->addValue('event_id', 'evt_test_789')
      ->addValue('event_type', 'checkout.session.completed')
      ->execute();
  }

  /**
   * Test that event_type is required.
   */
  public function testCreateWithoutEventTypeFails() {
    $this->expectException(\CRM_Core_Exception::class);

    PaymentWebhook::create(FALSE)
      ->addValue('event_id', 'evt_test_101')
      ->addValue('processor_type', 'stripe')
      ->execute();
  }

  /**
   * Test unique constraint on event_id.
   */
  public function testUniqueEventIdConstraint() {
    // Create first webhook
    PaymentWebhook::create(FALSE)
      ->addValue('event_id', 'evt_test_unique_202')
      ->addValue('processor_type', 'stripe')
      ->addValue('event_type', 'checkout.session.completed')
      ->execute();

    // Try to create duplicate - should fail
    $this->expectException(\CRM_Core_Exception::class);

    PaymentWebhook::create(FALSE)
      ->addValue('event_id', 'evt_test_unique_202')
      ->addValue('processor_type', 'stripe')
      ->addValue('event_type', 'checkout.session.completed')
      ->execute();
  }

  /**
   * Test retrieving PaymentWebhook by event_id.
   */
  public function testGetByEventId() {
    $created = PaymentWebhook::create(FALSE)
      ->addValue('event_id', 'evt_test_303')
      ->addValue('processor_type', 'stripe')
      ->addValue('event_type', 'payment_intent.succeeded')
      ->execute()
      ->first();

    $retrieved = PaymentWebhook::get(FALSE)
      ->addWhere('event_id', '=', 'evt_test_303')
      ->execute()
      ->first();

    $this->assertEquals($created['id'], $retrieved['id']);
    $this->assertEquals('evt_test_303', $retrieved['event_id']);
  }

  /**
   * Test retrieving PaymentWebhook by processor type and event type.
   */
  public function testGetByProcessorAndEventType() {
    PaymentWebhook::create(FALSE)
      ->addValue('event_id', 'evt_test_404')
      ->addValue('processor_type', 'stripe')
      ->addValue('event_type', 'checkout.session.completed')
      ->execute();

    $retrieved = PaymentWebhook::get(FALSE)
      ->addWhere('processor_type', '=', 'stripe')
      ->addWhere('event_type', '=', 'checkout.session.completed')
      ->execute()
      ->first();

    $this->assertEquals('stripe', $retrieved['processor_type']);
    $this->assertEquals('checkout.session.completed', $retrieved['event_type']);
  }

  /**
   * Test updating PaymentWebhook status.
   */
  public function testUpdateStatus() {
    $webhook = PaymentWebhook::create(FALSE)
      ->addValue('event_id', 'evt_test_505')
      ->addValue('processor_type', 'stripe')
      ->addValue('event_type', 'checkout.session.completed')
      ->addValue('status', 'new')
      ->execute()
      ->first();

    PaymentWebhook::update(FALSE)
      ->addWhere('id', '=', $webhook['id'])
      ->addValue('status', 'processed')
      ->addValue('result', 'applied')
      ->addValue('processed_at', date('Y-m-d H:i:s'))
      ->execute();

    $updated = PaymentWebhook::get(FALSE)
      ->addWhere('id', '=', $webhook['id'])
      ->execute()
      ->first();

    $this->assertEquals('processed', $updated['status']);
    $this->assertEquals('applied', $updated['result']);
    $this->assertNotEmpty($updated['processed_at']);
  }

  /**
   * Test deleting PaymentWebhook.
   */
  public function testDelete() {
    $webhook = PaymentWebhook::create(FALSE)
      ->addValue('event_id', 'evt_test_606')
      ->addValue('processor_type', 'stripe')
      ->addValue('event_type', 'checkout.session.completed')
      ->execute()
      ->first();

    PaymentWebhook::delete(FALSE)
      ->addWhere('id', '=', $webhook['id'])
      ->execute();

    $count = PaymentWebhook::get(FALSE)
      ->addWhere('id', '=', $webhook['id'])
      ->execute()
      ->count();

    $this->assertEquals(0, $count);
  }

  /**
   * Test SET NULL when payment attempt is deleted.
   */
  public function testSetNullWhenPaymentAttemptDeleted() {
    $webhook = PaymentWebhook::create(FALSE)
      ->addValue('event_id', 'evt_test_707')
      ->addValue('processor_type', 'stripe')
      ->addValue('event_type', 'checkout.session.completed')
      ->addValue('payment_attempt_id', $this->attemptId)
      ->execute()
      ->first();

    // Delete payment attempt
    PaymentAttempt::delete(FALSE)
      ->addWhere('id', '=', $this->attemptId)
      ->execute();

    // PaymentWebhook should still exist but with NULL payment_attempt_id
    $retrieved = PaymentWebhook::get(FALSE)
      ->addWhere('id', '=', $webhook['id'])
      ->execute()
      ->first();

    $this->assertNull($retrieved['payment_attempt_id']);
  }

  /**
   * Test different processor types.
   */
  public function testDifferentProcessorTypes() {
    $processors = [
      ['type' => 'stripe', 'event' => 'evt_stripe_808'],
      ['type' => 'gocardless', 'event' => 'evt_gc_809'],
      ['type' => 'itas', 'event' => 'evt_itas_810'],
    ];

    foreach ($processors as $processor) {
      $webhook = PaymentWebhook::create(FALSE)
        ->addValue('event_id', $processor['event'])
        ->addValue('processor_type', $processor['type'])
        ->addValue('event_type', 'payment.succeeded')
        ->execute()
        ->first();

      $this->assertEquals($processor['type'], $webhook['processor_type']);
      $this->assertEquals($processor['event'], $webhook['event_id']);
    }
  }

  /**
   * Test all valid statuses.
   */
  public function testAllStatuses() {
    $statuses = ['new', 'processing', 'processed', 'error'];

    foreach ($statuses as $index => $status) {
      $webhook = PaymentWebhook::create(FALSE)
        ->addValue('event_id', "evt_test_status_{$index}_911")
        ->addValue('processor_type', 'stripe')
        ->addValue('event_type', 'checkout.session.completed')
        ->addValue('status', $status)
        ->execute()
        ->first();

      $this->assertEquals($status, $webhook['status']);
    }
  }

  /**
   * Test all valid results.
   */
  public function testAllResults() {
    $results = ['applied', 'noop', 'ignored_out_of_order', 'error'];

    foreach ($results as $index => $result) {
      $webhook = PaymentWebhook::create(FALSE)
        ->addValue('event_id', "evt_test_result_{$index}_1001")
        ->addValue('processor_type', 'stripe')
        ->addValue('event_type', 'checkout.session.completed')
        ->addValue('status', 'processed')
        ->addValue('result', $result)
        ->execute()
        ->first();

      $this->assertEquals($result, $webhook['result']);
    }
  }

  /**
   * Test filtering by status.
   */
  public function testFilterByStatus() {
    // Create webhooks with different statuses
    PaymentWebhook::create(FALSE)
      ->addValue('event_id', 'evt_test_1101')
      ->addValue('processor_type', 'stripe')
      ->addValue('event_type', 'checkout.session.completed')
      ->addValue('status', 'new')
      ->execute();

    PaymentWebhook::create(FALSE)
      ->addValue('event_id', 'evt_test_1102')
      ->addValue('processor_type', 'stripe')
      ->addValue('event_type', 'checkout.session.completed')
      ->addValue('status', 'processed')
      ->execute();

    $newCount = PaymentWebhook::get(FALSE)
      ->addWhere('status', '=', 'new')
      ->execute()
      ->count();

    $processedCount = PaymentWebhook::get(FALSE)
      ->addWhere('status', '=', 'processed')
      ->execute()
      ->count();

    $this->assertGreaterThanOrEqual(1, $newCount);
    $this->assertGreaterThanOrEqual(1, $processedCount);
  }

  /**
   * Test webhook without payment_attempt_id (not all webhooks link to attempts).
   */
  public function testCreateWithoutPaymentAttempt() {
    $webhook = PaymentWebhook::create(FALSE)
      ->addValue('event_id', 'evt_test_1203')
      ->addValue('processor_type', 'stripe')
      ->addValue('event_type', 'account.updated')
      ->addValue('status', 'new')
      ->execute()
      ->first();

    $this->assertNotEmpty($webhook['id']);
    $this->assertEquals('evt_test_1203', $webhook['event_id']);
    $this->assertTrue(!isset($webhook['payment_attempt_id']) || empty($webhook['payment_attempt_id']));
  }

  /**
   * Test error logging.
   */
  public function testErrorLogging() {
    $errorMessage = 'Test error: payment processing failed';

    $webhook = PaymentWebhook::create(FALSE)
      ->addValue('event_id', 'evt_test_1304')
      ->addValue('processor_type', 'stripe')
      ->addValue('event_type', 'checkout.session.completed')
      ->addValue('status', 'error')
      ->addValue('result', 'error')
      ->addValue('error_log', $errorMessage)
      ->execute()
      ->first();

    $this->assertEquals('error', $webhook['status']);
    $this->assertEquals($errorMessage, $webhook['error_log']);
  }

}
