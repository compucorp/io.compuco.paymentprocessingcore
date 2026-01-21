<?php
use CRM_Paymentprocessingcore_ExtensionUtil as E;

/**
 * Business Access Object for PaymentWebhook entity (generic across all processors)
 *
 * Webhook event log for de-duplication and idempotency across all processors.
 * Prevents duplicate webhook processing using unique event_id constraint.
 */
class CRM_Paymentprocessingcore_BAO_PaymentWebhook extends CRM_Paymentprocessingcore_DAO_PaymentWebhook {

  /**
   * Create a new PaymentWebhook based on array-data
   *
   * @param array $params key-value pairs
   * @return CRM_Paymentprocessingcore_DAO_PaymentWebhook|NULL
   */
  public static function create($params) {
    $className = 'CRM_Paymentprocessingcore_DAO_PaymentWebhook';
    $entityName = 'PaymentWebhook';
    $hook = empty($params['id']) ? 'create' : 'edit';

    CRM_Utils_Hook::pre($hook, $entityName, CRM_Utils_Array::value('id', $params), $params);
    $instance = new $className();
    $instance->copyValues($params);
    $instance->save();
    CRM_Utils_Hook::post($hook, $entityName, $instance->id, $instance);

    return $instance;
  }

  /**
   * Find a PaymentWebhook record by event ID
   *
   * @param string $eventId Processor event ID (evt_... for Stripe)
   * @return array|null Array of webhook data or NULL if not found
   */
  public static function findByEventId($eventId) {
    if (empty($eventId)) {
      return NULL;
    }

    $webhook = new self();
    $webhook->event_id = $eventId;

    if ($webhook->find(TRUE)) {
      return $webhook->toArray();
    }

    return NULL;
  }

  /**
   * Check if an event has already been processed (for idempotency)
   *
   * Returns TRUE for webhooks with status 'processed' or 'processing'.
   * This prevents duplicate processing attempts - if a webhook is currently
   * being processed by another worker, callers should skip it.
   *
   * Stuck webhooks (in 'processing' too long) are handled separately by
   * resetStuckWebhooks() which resets their status to 'new', after which
   * this method will return FALSE.
   *
   * @param string $eventId Processor event ID
   * @return bool TRUE if event is processed or being processed, FALSE otherwise
   */
  public static function isProcessed($eventId) {
    $webhook = self::findByEventId($eventId);
    return !empty($webhook) && in_array($webhook['status'], ['processed', 'processing']);
  }

  /**
   * Maximum number of retry attempts before marking as permanent error.
   */
  public const MAX_RETRY_ATTEMPTS = 3;

  /**
   * Base delay in seconds for exponential backoff (5 minutes).
   */
  public const RETRY_BASE_DELAY = 300;

  /**
   * Get available statuses for PaymentWebhook
   *
   * @return array Status options
   */
  public static function getStatuses() {
    return [
      'new' => E::ts('New'),
      'processing' => E::ts('Processing'),
      'processed' => E::ts('Processed'),
      'error' => E::ts('Error'),
      'permanent_error' => E::ts('Permanent Error'),
    ];
  }

  /**
   * Validate that a status value is valid.
   *
   * @param string $status Status to validate
   *
   * @return void
   *
   * @throws \InvalidArgumentException If status is not valid
   */
  private static function validateStatus(string $status): void {
    $validStatuses = array_keys(self::getStatuses());
    if (!in_array($status, $validStatuses, TRUE)) {
      throw new \InvalidArgumentException(
        sprintf(
          'Invalid PaymentWebhook status "%s". Valid statuses are: %s',
          $status,
          implode(', ', $validStatuses)
        )
      );
    }
  }

  /**
   * Update webhook status and optional result/error fields.
   *
   * Automatically sets processed_at timestamp when status is 'processed' or 'error'.
   *
   * @param int $id Webhook ID
   * @param string $status New status: 'new', 'processing', 'processed', 'error'
   * @param string|null $result Processing result code: 'applied', 'noop', 'error', etc.
   * @param string|null $errorLog Error details if processing failed
   */
  public static function updateStatus(int $id, string $status, ?string $result = NULL, ?string $errorLog = NULL): void {
    self::validateStatus($status);

    $params = [
      'id' => $id,
      'status' => $status,
    ];

    if ($result !== NULL) {
      $params['result'] = $result;
    }

    if ($errorLog !== NULL) {
      $params['error_log'] = $errorLog;
    }

    // Set processed_at for terminal statuses
    if (in_array($status, ['processed', 'error'], TRUE)) {
      $params['processed_at'] = date('Y-m-d H:i:s');
    }

    self::writeRecord($params);
  }

  /**
   * Atomically update webhook status with optimistic locking.
   *
   * Only updates if the current status matches the expected status.
   * This prevents race conditions when multiple workers try to process
   * the same webhook simultaneously.
   *
   * @param int $id Webhook ID
   * @param string $expectedStatus Current expected status
   * @param string $newStatus New status to set
   *
   * @return bool TRUE if update was successful, FALSE if status didn't match
   */
  public static function updateStatusAtomic(int $id, string $expectedStatus, string $newStatus): bool {
    $values = ['status' => $newStatus];

    // If transitioning to 'processing', set processing_started_at timestamp
    if ($newStatus === 'processing') {
      $values['processing_started_at'] = date('Y-m-d H:i:s');
    }

    $result = \Civi\Api4\PaymentWebhook::update(FALSE)
      ->addWhere('id', '=', $id)
      ->addWhere('status', '=', $expectedStatus)
      ->setValues($values)
      ->execute();

    return $result->count() > 0;
  }

  /**
   * Check if a webhook event is a duplicate (for idempotency).
   *
   * Checks if an event with the same event_id and processor_type already exists.
   * The unique index UI_event_processor ensures uniqueness at database level.
   *
   * @param string $eventId Processor event ID (evt_... for Stripe)
   * @param string $processorType Processor type: 'stripe', 'gocardless', etc.
   *
   * @return bool TRUE if event already exists, FALSE otherwise
   */
  public static function isDuplicate(string $eventId, string $processorType): bool {
    $count = \Civi\Api4\PaymentWebhook::get(FALSE)
      ->selectRowCount()
      ->addWhere('event_id', '=', $eventId)
      ->addWhere('processor_type', '=', $processorType)
      ->execute()
      ->countMatched();

    return $count > 0;
  }

  /**
   * Find webhook by event ID and processor type.
   *
   * @param string $eventId Processor event ID
   * @param string $processorType Processor type
   *
   * @return array|null Webhook record or NULL if not found
   */
  public static function findByEventIdAndProcessor(string $eventId, string $processorType): ?array {
    return \Civi\Api4\PaymentWebhook::get(FALSE)
      ->addWhere('event_id', '=', $eventId)
      ->addWhere('processor_type', '=', $processorType)
      ->execute()
      ->first();
  }

  /**
   * Increment the attempt counter for a webhook.
   *
   * @param int $id Webhook ID
   *
   * @return int The new attempt count
   */
  public static function incrementAttempts(int $id): int {
    $webhook = \Civi\Api4\PaymentWebhook::get(FALSE)
      ->addSelect('attempts')
      ->addWhere('id', '=', $id)
      ->execute()
      ->first();

    $newAttempts = ($webhook['attempts'] ?? 0) + 1;

    self::writeRecord([
      'id' => $id,
      'attempts' => $newAttempts,
    ]);

    return $newAttempts;
  }

  /**
   * Check if a webhook has exceeded max retry attempts.
   *
   * @param int $attempts Current attempt count
   *
   * @return bool TRUE if max attempts reached or exceeded
   */
  public static function hasExceededMaxAttempts(int $attempts): bool {
    return $attempts >= self::MAX_RETRY_ATTEMPTS;
  }

  /**
   * Calculate the delay in seconds for the next retry using exponential backoff.
   *
   * Delays: 5 min (attempt 1), 15 min (attempt 2), 45 min (attempt 3)
   * Formula: base_delay * 3^(attempt - 1)
   *
   * @param int $attempts Current attempt count
   *
   * @return int Delay in seconds
   */
  public static function calculateRetryDelay(int $attempts): int {
    return self::RETRY_BASE_DELAY * (int) pow(3, $attempts - 1);
  }

  /**
   * Mark webhook for retry with exponential backoff.
   *
   * Sets status to 'error' and calculates next_retry_at timestamp.
   *
   * @param int $id Webhook ID
   * @param int $attempts Current attempt count
   * @param string $errorLog Error message from failed attempt
   */
  public static function markForRetry(int $id, int $attempts, string $errorLog): void {
    $delay = self::calculateRetryDelay($attempts);
    $nextRetryAt = date('Y-m-d H:i:s', time() + $delay);

    self::writeRecord([
      'id' => $id,
      'status' => 'error',
      'error_log' => $errorLog,
      'next_retry_at' => $nextRetryAt,
    ]);

    \Civi::log()->info('Webhook marked for retry', [
      'webhook_id' => $id,
      'attempts' => $attempts,
      'delay_seconds' => $delay,
      'next_retry_at' => $nextRetryAt,
    ]);
  }

  /**
   * Mark webhook as permanent error (max retries exceeded).
   *
   * @param int $id Webhook ID
   * @param string $errorLog Error message
   */
  public static function markPermanentError(int $id, string $errorLog): void {
    self::writeRecord([
      'id' => $id,
      'status' => 'permanent_error',
      'result' => 'error',
      'error_log' => $errorLog,
      'processed_at' => date('Y-m-d H:i:s'),
    ]);

    \Civi::log()->error('Webhook marked as permanent error after max retries', [
      'webhook_id' => $id,
      'error' => $errorLog,
    ]);
  }

  /**
   * Get webhooks that are ready for retry.
   *
   * Returns webhooks with status='error' and next_retry_at <= NOW().
   *
   * @param string $processorType Processor type filter
   * @param int $limit Maximum number of webhooks to return
   *
   * @return array List of webhook records
   */
  public static function getWebhooksReadyForRetry(string $processorType, int $limit = 50): array {
    return \Civi\Api4\PaymentWebhook::get(FALSE)
      ->addWhere('processor_type', '=', $processorType)
      ->addWhere('status', '=', 'error')
      ->addWhere('next_retry_at', '<=', date('Y-m-d H:i:s'))
      ->addWhere('attempts', '<', self::MAX_RETRY_ATTEMPTS)
      ->setLimit($limit)
      ->addOrderBy('next_retry_at', 'ASC')
      ->execute()
      ->getArrayCopy();
  }

  /**
   * Batch update status for multiple webhooks.
   *
   * Uses a single SQL UPDATE query to update all webhooks at once,
   * avoiding N+1 query issues when processing many records.
   *
   * @param array $ids List of webhook IDs to update
   * @param string $status New status
   */
  public static function batchUpdateStatus(array $ids, string $status): void {
    if (empty($ids)) {
      return;
    }

    self::validateStatus($status);

    // Sanitize IDs to prevent SQL injection
    $sanitizedIds = array_map('intval', $ids);
    $idList = implode(',', $sanitizedIds);

    $sql = "UPDATE civicrm_payment_webhook
            SET status = %1
            WHERE id IN ({$idList})";

    \CRM_Core_DAO::executeQuery($sql, [
      1 => [$status, 'String'],
    ]);
  }

  /**
   * Maximum number of stuck webhooks to reset per run.
   *
   * Prevents unbounded loop when many webhooks are stuck.
   */
  public const MAX_STUCK_RESET_LIMIT = 100;

  /**
   * Reset stuck webhooks that have been processing for too long.
   *
   * Webhooks stuck in 'processing' for more than 30 minutes are reset to 'new'.
   * This handles cases where the processor crashed mid-processing.
   *
   * Limited to MAX_STUCK_RESET_LIMIT per run to prevent unbounded loops.
   *
   * @param int $timeoutMinutes Minutes after which to consider a webhook stuck
   *
   * @return int Number of webhooks reset
   */
  public static function resetStuckWebhooks(int $timeoutMinutes = 30): int {
    $cutoff = date('Y-m-d H:i:s', strtotime("-{$timeoutMinutes} minutes"));

    $stuckWebhooks = \Civi\Api4\PaymentWebhook::get(FALSE)
      ->addSelect('id')
      ->addWhere('status', '=', 'processing')
      ->addWhere('processing_started_at', 'IS NOT NULL')
      ->addWhere('processing_started_at', '<', $cutoff)
      ->setLimit(self::MAX_STUCK_RESET_LIMIT)
      ->execute();

    $webhookIds = array_column($stuckWebhooks->getArrayCopy(), 'id');

    if (empty($webhookIds)) {
      return 0;
    }

    // Batch update all stuck webhooks at once (avoids N+1)
    $idList = implode(',', array_map('intval', $webhookIds));
    $errorLog = 'Reset from stuck processing state after ' . $timeoutMinutes . ' minutes';

    $sql = "UPDATE civicrm_payment_webhook
            SET status = 'new', error_log = %1
            WHERE id IN ({$idList})";

    \CRM_Core_DAO::executeQuery($sql, [
      1 => [$errorLog, 'String'],
    ]);

    $count = count($webhookIds);

    if ($count > 0) {
      \Civi::log()->warning('Reset stuck webhooks', [
        'count' => $count,
        'timeout_minutes' => $timeoutMinutes,
        'webhook_ids' => $webhookIds,
        'limit_applied' => $count >= self::MAX_STUCK_RESET_LIMIT,
      ]);
    }

    return $count;
  }

}
