<?php

namespace Civi\Paymentprocessingcore\Service;

/**
 * Abstract base class for payment processor webhook receivers.
 *
 * Provides common functionality for receiving and saving webhook events.
 * Processor-specific extensions should extend this class and:
 * - Implement getProcessorType() to identify the processor
 * - Override saveWebhookEvent() if atomic operations are needed
 * - Implement handleRequest() for processor-specific validation
 *
 * @package Civi\Paymentprocessingcore\Service
 */
abstract class WebhookReceiverService {

  /**
   * The webhook queue service.
   *
   * @var \Civi\Paymentprocessingcore\Service\WebhookQueueService
   */
  protected WebhookQueueService $queueService;

  /**
   * WebhookReceiverService constructor.
   *
   * @param \Civi\Paymentprocessingcore\Service\WebhookQueueService $queueService
   *   The webhook queue service.
   */
  public function __construct(WebhookQueueService $queueService) {
    $this->queueService = $queueService;
  }

  /**
   * Get the processor type identifier.
   *
   * This should return a lowercase string identifying the processor
   * (e.g., 'stripe', 'gocardless'). Implementations should cache this
   * value as it's called frequently.
   *
   * @return string
   *   The processor type identifier.
   */
  abstract public function getProcessorType(): string;

  /**
   * Handle incoming webhook request.
   *
   * Processor-specific implementations should:
   * 1. Verify the webhook signature/authenticity
   * 2. Parse the event payload
   * 3. Filter allowed event types
   * 4. Call saveWebhookEvent() to persist
   * 5. Call queueWebhook() to queue for processing
   *
   * @param string $payload
   *   Raw POST body from the payment processor.
   * @param string $signature
   *   Signature header value for verification.
   */
  abstract public function handleRequest(string $payload, string $signature): void;

  /**
   * Save webhook event to database.
   *
   * Default implementation uses API4 with check-then-create pattern.
   * Override this method for atomic operations (e.g., INSERT IGNORE)
   * when race conditions are a concern.
   *
   * @param string $eventId
   *   Unique event identifier from the payment processor.
   * @param string $eventType
   *   Event type (e.g., 'payment_intent.succeeded').
   * @param int|null $paymentAttemptId
   *   Optional FK to payment_attempt record.
   *
   * @return int|null
   *   The created webhook record ID, or NULL if duplicate.
   */
  protected function saveWebhookEvent(
    string $eventId,
    string $eventType,
    ?int $paymentAttemptId = NULL
  ): ?int {
    // Check if already exists (race condition possible here)
    $existing = \Civi\Api4\PaymentWebhook::get(FALSE)
      ->addSelect('id')
      ->addWhere('event_id', '=', $eventId)
      ->addWhere('processor_type', '=', $this->getProcessorType())
      ->execute()
      ->first();

    if ($existing) {
      return NULL;
    }

    // Create new record
    $result = \Civi\Api4\PaymentWebhook::create(FALSE)
      ->addValue('event_id', $eventId)
      ->addValue('processor_type', $this->getProcessorType())
      ->addValue('event_type', $eventType)
      ->addValue('payment_attempt_id', $paymentAttemptId)
      ->addValue('status', 'new')
      ->addValue('attempts', 0)
      ->execute()
      ->first();

    return $result['id'] ?? NULL;
  }

  /**
   * Queue webhook for asynchronous processing.
   *
   * Adds the webhook to the processor-specific queue for later
   * processing by WebhookQueueRunnerService.
   *
   * @param int $webhookId
   *   The PaymentWebhook record ID.
   * @param array $eventData
   *   Parsed event data to pass to the handler.
   */
  protected function queueWebhook(int $webhookId, array $eventData): void {
    $this->queueService->addTask(
      $this->getProcessorType(),
      $webhookId,
      ['event_data' => $eventData]
    );
  }

  /**
   * Find payment attempt ID by processor payment ID.
   *
   * Looks up the payment attempt using the processor-specific
   * payment identifier (e.g., Stripe payment intent ID).
   *
   * @param string|null $processorPaymentId
   *   The processor's payment identifier.
   *
   * @return int|null
   *   Payment attempt ID or NULL if not found.
   */
  protected function findPaymentAttemptId(?string $processorPaymentId): ?int {
    if ($processorPaymentId === NULL) {
      return NULL;
    }

    $attempt = \Civi\Api4\PaymentAttempt::get(FALSE)
      ->addSelect('id')
      ->addWhere('processor_payment_id', '=', $processorPaymentId)
      ->addWhere('processor_type', '=', $this->getProcessorType())
      ->execute()
      ->first();

    return $attempt['id'] ?? NULL;
  }

}
