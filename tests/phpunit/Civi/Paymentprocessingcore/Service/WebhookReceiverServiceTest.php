<?php

namespace Civi\Paymentprocessingcore\Service;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\PaymentAttempt;
use Civi\Api4\PaymentWebhook;

/**
 * Unit tests for WebhookReceiverService.
 *
 * Tests the abstract base class via a concrete implementation.
 *
 * @group headless
 */
class WebhookReceiverServiceTest extends \BaseHeadlessTest {

  /**
   * Test contact ID.
   *
   * @var int
   */
  private int $contactId;

  /**
   * Test contribution ID.
   *
   * @var int
   */
  private int $contributionId;

  /**
   * Test payment attempt ID.
   *
   * @var int
   */
  private int $attemptId;

  /**
   * Concrete webhook receiver implementation for testing.
   *
   * @var \Civi\Paymentprocessingcore\Service\TestWebhookReceiverService
   */
  private TestWebhookReceiverService $receiver;

  /**
   * Set up test fixtures.
   */
  public function setUp(): void {
    parent::setUp();

    // Create test contact
    $this->contactId = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Webhook')
      ->addValue('last_name', 'Test')
      ->execute()
      ->first()['id'];

    // Create pending contribution
    $this->contributionId = Contribution::create(FALSE)
      ->addValue('contact_id', $this->contactId)
      ->addValue('financial_type_id:name', 'Donation')
      ->addValue('total_amount', 100.00)
      ->addValue('currency', 'GBP')
      ->addValue('contribution_status_id:name', 'Pending')
      ->execute()
      ->first()['id'];

    // Create payment attempt
    $attempt = PaymentAttempt::create(FALSE)
      ->addValue('contribution_id', $this->contributionId)
      ->addValue('contact_id', $this->contactId)
      ->addValue('processor_type', 'test')
      ->addValue('processor_payment_id', 'pi_test_12345')
      ->addValue('status', 'pending')
      ->execute()
      ->first();

    $this->attemptId = $attempt['id'];

    // Create concrete implementation for testing
    $queueService = \Civi::service('paymentprocessingcore.webhook_queue');
    $this->receiver = new TestWebhookReceiverService($queueService);
  }

  /**
   * Test saveWebhookEvent() creates webhook record.
   */
  public function testSaveWebhookEventCreatesWebhookRecord() {
    $eventId = 'evt_test_' . uniqid();

    $webhookId = $this->receiver->publicSaveWebhookEvent(
      $eventId,
      'payment.succeeded',
      $this->attemptId
    );

    $this->assertNotNull($webhookId);
    $this->assertIsInt($webhookId);

    // Verify webhook was created
    $webhook = PaymentWebhook::get(FALSE)
      ->addWhere('id', '=', $webhookId)
      ->execute()
      ->first();

    $this->assertEquals($eventId, $webhook['event_id']);
    $this->assertEquals('test', $webhook['processor_type']);
    $this->assertEquals('payment.succeeded', $webhook['event_type']);
    $this->assertEquals($this->attemptId, $webhook['payment_attempt_id']);
    $this->assertEquals('new', $webhook['status']);
  }

  /**
   * Test saveWebhookEvent() returns NULL for duplicate event.
   */
  public function testSaveWebhookEventReturnsNullForDuplicateEvent() {
    $eventId = 'evt_test_duplicate_' . uniqid();

    // Create first webhook
    $webhookId1 = $this->receiver->publicSaveWebhookEvent(
      $eventId,
      'payment.succeeded'
    );

    $this->assertNotNull($webhookId1);

    // Try to create duplicate
    $webhookId2 = $this->receiver->publicSaveWebhookEvent(
      $eventId,
      'payment.succeeded'
    );

    $this->assertNull($webhookId2);
  }

  /**
   * Test saveWebhookEvent() allows same event_id for different processors.
   */
  public function testSaveWebhookEventAllowsSameEventIdForDifferentProcessors() {
    $eventId = 'evt_shared_' . uniqid();

    // Create webhook for 'test' processor
    $webhookId1 = $this->receiver->publicSaveWebhookEvent(
      $eventId,
      'payment.succeeded'
    );

    $this->assertNotNull($webhookId1);

    // Create webhook with same event_id but different processor
    $otherReceiver = new OtherWebhookReceiverService(\Civi::service('paymentprocessingcore.webhook_queue'));
    $webhookId2 = $otherReceiver->publicSaveWebhookEvent(
      $eventId,
      'payment.succeeded'
    );

    // Should succeed because unique constraint is (processor_type, event_id)
    $this->assertNotNull($webhookId2);
    $this->assertNotEquals($webhookId1, $webhookId2);
  }

  /**
   * Test queueWebhook() adds webhook to queue.
   */
  public function testQueueWebhookAddsWebhookToQueue() {
    $webhookId = $this->receiver->publicSaveWebhookEvent(
      'evt_test_queue_' . uniqid(),
      'payment.succeeded'
    );

    // Reset queue initialization to ensure clean state
    WebhookQueueService::resetInitialization();

    $queueService = \Civi::service('paymentprocessingcore.webhook_queue');
    $initialCount = $queueService->getQueueCount('test');

    $this->receiver->publicQueueWebhook($webhookId, ['foo' => 'bar']);

    $finalCount = $queueService->getQueueCount('test');
    $this->assertEquals($initialCount + 1, $finalCount);
  }

  /**
   * Test findPaymentAttemptId() finds attempt by processor payment ID.
   */
  public function testFindPaymentAttemptIdFindsAttemptByProcessorPaymentId() {
    $attemptId = $this->receiver->publicFindPaymentAttemptId('pi_test_12345');

    $this->assertEquals($this->attemptId, $attemptId);
  }

  /**
   * Test findPaymentAttemptId() returns NULL for unknown payment ID.
   */
  public function testFindPaymentAttemptIdReturnsNullForUnknownPaymentId() {
    $attemptId = $this->receiver->publicFindPaymentAttemptId('pi_unknown');

    $this->assertNull($attemptId);
  }

  /**
   * Test findPaymentAttemptId() returns NULL for NULL input.
   */
  public function testFindPaymentAttemptIdReturnsNullForNullInput() {
    $attemptId = $this->receiver->publicFindPaymentAttemptId(NULL);

    $this->assertNull($attemptId);
  }

  /**
   * Test findPaymentAttemptId() is processor-specific.
   */
  public function testFindPaymentAttemptIdIsProcessorSpecific() {
    // Should find the 'test' processor attempt by its payment ID
    $attemptId = $this->receiver->publicFindPaymentAttemptId('pi_test_12345');
    $this->assertEquals($this->attemptId, $attemptId);

    // Should not find payment ID from a different processor
    $notFound = $this->receiver->publicFindPaymentAttemptId('pi_from_different_processor');
    $this->assertNull($notFound);
  }

  /**
   * Test getProcessorType() returns correct processor type.
   */
  public function testGetProcessorTypeReturnsCorrectProcessorType() {
    $this->assertEquals('test', $this->receiver->getProcessorType());
  }

}
