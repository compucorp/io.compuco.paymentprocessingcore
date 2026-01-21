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
   * Save webhook event to database using INSERT IGNORE.
   *
   * Uses INSERT IGNORE for atomic duplicate prevention.
   * This prevents race conditions when payment processors send duplicate webhooks.
   * The database unique index (event_id, processor_type) ensures only one
   * record is created even if multiple concurrent requests arrive.
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
    // Use INSERT IGNORE to handle duplicates atomically
    // The unique index UI_event_processor will prevent duplicates
    // Handle NULL payment_attempt_id - CiviCRM's executeQuery doesn't support NULL with Integer type
    if ($paymentAttemptId === NULL) {
      $sql = "INSERT IGNORE INTO civicrm_payment_webhook
              (event_id, processor_type, event_type, payment_attempt_id, status, attempts, created_date)
              VALUES (%1, %2, %3, NULL, %4, %5, NOW())";
      $params = [
        1 => [$eventId, 'String'],
        2 => [$this->getProcessorType(), 'String'],
        3 => [$eventType, 'String'],
        4 => ['new', 'String'],
        5 => [0, 'Integer'],
      ];
    }
    else {
      $sql = "INSERT IGNORE INTO civicrm_payment_webhook
              (event_id, processor_type, event_type, payment_attempt_id, status, attempts, created_date)
              VALUES (%1, %2, %3, %4, %5, %6, NOW())";
      $params = [
        1 => [$eventId, 'String'],
        2 => [$this->getProcessorType(), 'String'],
        3 => [$eventType, 'String'],
        4 => [$paymentAttemptId, 'Integer'],
        5 => ['new', 'String'],
        6 => [0, 'Integer'],
      ];
    }

    $dao = \CRM_Core_DAO::executeQuery($sql, $params);

    // Check if insert was successful using affectedRows()
    // INSERT IGNORE returns 0 affected rows if duplicate was ignored
    $affectedRows = method_exists($dao, 'affectedRows') ? $dao->affectedRows() : 0;

    if ($affectedRows === 0) {
      // Duplicate - INSERT IGNORE skipped the insert
      return NULL;
    }

    // Query back using the unique key to get the inserted ID
    // This is safer than LAST_INSERT_ID() in connection pooling scenarios
    $id = \CRM_Core_DAO::singleValueQuery(
      "SELECT id FROM civicrm_payment_webhook WHERE event_id = %1 AND processor_type = %2",
      [
        1 => [$eventId, 'String'],
        2 => [$this->getProcessorType(), 'String'],
      ]
    );

    return $id !== NULL ? (int) $id : NULL;
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
