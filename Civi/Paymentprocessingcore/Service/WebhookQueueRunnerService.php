<?php

namespace Civi\Paymentprocessingcore\Service;

/**
 * Service for running webhook processing queues.
 *
 * This service is responsible for processing queued webhook events.
 * It uses CiviCRM's CRM_Queue_Runner to process tasks and delegates
 * to processor-specific handlers via the WebhookHandlerRegistry.
 *
 * Key features:
 * - runAllQueues(): Processes webhooks from ALL registered processors
 * - runQueue(): Processes webhooks for a single processor type
 * - Static runTask(): Callback for individual task processing
 * - Batch size limiting: Prevents timeout by limiting items per run
 * - Retry logic: Exponential backoff for failed webhooks
 *
 * @package Civi\Paymentprocessingcore\Service
 */
class WebhookQueueRunnerService {

  /**
   * Default batch size limit per processor per job run.
   *
   * Based on: 5-minute job timeout, 3 processors, ~0.4s per event
   * = 100 seconds per processor = 250 events max
   */
  public const DEFAULT_BATCH_SIZE = 250;

  /**
   * The webhook handler registry.
   *
   * @var \Civi\Paymentprocessingcore\Service\WebhookHandlerRegistry
   */
  private WebhookHandlerRegistry $registry;

  /**
   * WebhookQueueRunnerService constructor.
   *
   * @param \Civi\Paymentprocessingcore\Service\WebhookHandlerRegistry $registry
   */
  public function __construct(WebhookHandlerRegistry $registry) {
    $this->registry = $registry;
  }

  /**
   * Run queues for all registered processor types.
   *
   * This is the default mode when the scheduled job runs with
   * processor_type='all'. It processes webhooks from all enabled
   * payment processors (Stripe, GoCardless, Deluxe, etc.) in a
   * single job run.
   *
   * New processor extensions automatically appear here when they
   * register handlers with the registry via DI.
   *
   * @param int $batchSize Max items to process per processor (0 = unlimited)
   *
   * @return array<string, array> Results keyed by processor type
   */
  public function runAllQueues(int $batchSize = self::DEFAULT_BATCH_SIZE): array {
    $processorTypes = $this->registry->getRegisteredProcessorTypes();

    // First, reset any stuck webhooks across all processors
    $stuckReset = \CRM_Paymentprocessingcore_BAO_PaymentWebhook::resetStuckWebhooks();

    $results = [
      '_meta' => [
        'stuck_webhooks_reset' => $stuckReset,
        'batch_size' => $batchSize,
      ],
    ];

    foreach ($processorTypes as $processorType) {
      // First, re-queue any webhooks ready for retry
      $this->requeueRetryableWebhooks($processorType);

      // Then process the queue
      $results[$processorType] = $this->runQueue($processorType, $batchSize);
    }

    return $results;
  }

  /**
   * Run queue for a specific processor type.
   *
   * Processes pending webhook tasks in the processor's queue
   * up to the batch size limit.
   *
   * @param string $processorType
   *   The processor type (e.g., 'stripe', 'gocardless')
   * @param int $batchSize
   *   Max items to process (0 = unlimited)
   *
   * @return array<string,mixed>
   *   Queue runner result with keys: is_error, message, items_processed,
   *   items_failed, items_remaining
   */
  public function runQueue(string $processorType, int $batchSize = self::DEFAULT_BATCH_SIZE): array {
    // Reset any stuck webhooks before processing
    \CRM_Paymentprocessingcore_BAO_PaymentWebhook::resetStuckWebhooks();

    // Re-queue any webhooks ready for retry
    $this->requeueRetryableWebhooks($processorType);

    /** @var \Civi\Paymentprocessingcore\Service\WebhookQueueService $queueService */
    $queueService = \Civi::service('paymentprocessingcore.webhook_queue');
    $queue = $queueService->getQueue($processorType);

    $totalItems = $queue->numberOfItems();
    if ($totalItems === 0) {
      return [
        'is_error' => FALSE,
        'message' => sprintf('No items in %s webhook queue', $processorType),
        'items_processed' => 0,
        'items_remaining' => 0,
      ];
    }

    // Determine how many items to process
    $itemsToProcess = ($batchSize > 0) ? min($totalItems, $batchSize) : $totalItems;
    $processed = 0;
    $errors = 0;

    // Process items one at a time with batch limiting
    while ($processed < $itemsToProcess) {
      $item = $queue->claimItem();
      if (!$item) {
        break;
      }

      $shouldDelete = FALSE;
      try {
        // Execute the task callback
        $taskResult = call_user_func_array($item->data->callback, array_merge(
          [new \CRM_Queue_TaskContext()],
          $item->data->arguments
        ));

        $shouldDelete = (bool) $taskResult;
        if (!$taskResult) {
          $errors++;
        }
      }
      catch (\Exception $e) {
        // Extract webhook_id from task arguments if available
        $webhookId = $item->data->arguments[0] ?? 'unknown';

        \Civi::log()->error('Queue task failed', [
          'processor_type' => $processorType,
          'webhook_id' => $webhookId,
          'error' => $e->getMessage(),
          'exception_class' => get_class($e),
          'trace' => $e->getTraceAsString(),
        ]);
        $shouldDelete = FALSE;
        $errors++;
      }
      finally {
        // Ensure item is always released or deleted, preventing item loss
        // if an exception occurs during deleteItem() or releaseItem()
        try {
          if ($shouldDelete) {
            $queue->deleteItem($item);
          }
          else {
            $queue->releaseItem($item);
          }
        }
        catch (\Exception $e) {
          \Civi::log()->error('Failed to release/delete queue item', [
            'processor_type' => $processorType,
            'error' => $e->getMessage(),
          ]);
        }
      }

      $processed++;
    }

    $itemsRemaining = $queue->numberOfItems();

    return [
      'is_error' => $errors > 0,
      'message' => sprintf(
        'Processed %d/%d %s webhooks (%d errors, %d remaining)',
        $processed - $errors,
        $processed,
        $processorType,
        $errors,
        $itemsRemaining
      ),
      'items_processed' => $processed - $errors,
      'items_failed' => $errors,
      'items_remaining' => $itemsRemaining,
    ];
  }

  /**
   * Re-queue webhooks that are ready for retry.
   *
   * Finds webhooks with status='error' and next_retry_at <= NOW()
   * and adds them back to the processing queue.
   *
   * Uses batch update to avoid N+1 query issues when processing
   * many webhooks ready for retry.
   *
   * @param string $processorType The processor type
   *
   * @return int Number of webhooks re-queued
   */
  private function requeueRetryableWebhooks(string $processorType): int {
    $webhooks = \CRM_Paymentprocessingcore_BAO_PaymentWebhook::getWebhooksReadyForRetry(
      $processorType,
      50
    );

    if (empty($webhooks)) {
      return 0;
    }

    // Extract IDs for batch update
    $webhookIds = array_column($webhooks, 'id');

    // Batch update status to 'new' for all webhooks at once (avoids N+1)
    \CRM_Paymentprocessingcore_BAO_PaymentWebhook::batchUpdateStatus($webhookIds, 'new');

    /** @var \Civi\Paymentprocessingcore\Service\WebhookQueueService $queueService */
    $queueService = \Civi::service('paymentprocessingcore.webhook_queue');

    // Add all webhooks to queue
    foreach ($webhooks as $webhook) {
      // Add back to queue (we don't have original event_data, so pass empty)
      // The handler will need to fetch from external API if needed
      $queueService->addTask($processorType, $webhook['id'], []);
    }

    $count = count($webhookIds);

    if ($count > 0) {
      \Civi::log()->info('Re-queued webhooks for retry', [
        'processor_type' => $processorType,
        'count' => $count,
        'webhook_ids' => $webhookIds,
      ]);
    }

    return $count;
  }

  /**
   * Process a single webhook task.
   *
   * This static method is called by CRM_Queue_Runner for each queued item.
   * It loads the webhook record to get processor_type and event_type,
   * then uses the registry to find and execute the appropriate handler.
   *
   * Implements retry logic with exponential backoff:
   * - On failure, increments attempt counter
   * - If attempts < max (3), marks for retry with backoff delay
   * - If attempts >= max, marks as permanent_error
   *
   * @param \CRM_Queue_TaskContext $ctx Queue task context
   * @param int $webhookId ID of the civicrm_payment_webhook record
   * @param array $params Additional parameters including 'event_data'
   *
   * @return bool TRUE to continue processing other tasks
   */
  public static function runTask(\CRM_Queue_TaskContext $ctx, int $webhookId, array $params): bool {
    // Load webhook record to get processor_type and event_type
    $webhook = \Civi\Api4\PaymentWebhook::get(FALSE)
      ->addSelect('id', 'processor_type', 'event_type', 'status', 'attempts')
      ->addWhere('id', '=', $webhookId)
      ->execute()
      ->first();

    if (!$webhook) {
      \Civi::log()->error('Webhook record not found', ['webhook_id' => $webhookId]);
      return TRUE;
    }

    // Skip if already processed or permanently failed (idempotency)
    if (in_array($webhook['status'], ['processed', 'permanent_error'], TRUE)) {
      \Civi::log()->info('Webhook already processed or in terminal state', [
        'webhook_id' => $webhookId,
        'status' => $webhook['status'],
      ]);
      return TRUE;
    }

    // Atomically update status to 'processing' (prevents race condition)
    // Only one worker can successfully claim this webhook
    $claimed = \CRM_Paymentprocessingcore_BAO_PaymentWebhook::updateStatusAtomic(
      $webhookId,
      $webhook['status'],
      'processing'
    );

    if (!$claimed) {
      // Another worker already claimed this webhook
      \Civi::log()->info('Webhook already claimed by another worker', [
        'webhook_id' => $webhookId,
        'expected_status' => $webhook['status'],
      ]);
      return TRUE;
    }

    // Increment attempt counter
    $attempts = \CRM_Paymentprocessingcore_BAO_PaymentWebhook::incrementAttempts($webhookId);

    try {
      /** @var \Civi\Paymentprocessingcore\Service\WebhookHandlerRegistry $registry */
      $registry = \Civi::service('paymentprocessingcore.webhook_handler_registry');

      // Check if handler exists
      if (!$registry->hasHandler($webhook['processor_type'], $webhook['event_type'])) {
        \Civi::log()->warning('No handler registered for webhook event', [
          'webhook_id' => $webhookId,
          'processor_type' => $webhook['processor_type'],
          'event_type' => $webhook['event_type'],
        ]);
        \CRM_Paymentprocessingcore_BAO_PaymentWebhook::updateStatus(
          $webhookId,
          'processed',
          'no_handler'
        );
        return TRUE;
      }

      // Get and execute handler
      $handler = $registry->getHandler($webhook['processor_type'], $webhook['event_type']);
      $result = $handler->handle($webhookId, $params);

      // Update status to processed
      \CRM_Paymentprocessingcore_BAO_PaymentWebhook::updateStatus($webhookId, 'processed', $result);

      \Civi::log()->info('Webhook processed successfully', [
        'webhook_id' => $webhookId,
        'processor_type' => $webhook['processor_type'],
        'event_type' => $webhook['event_type'],
        'result' => $result,
        'attempts' => $attempts,
      ]);

      return TRUE;
    }
    catch (\Exception $e) {
      $errorMessage = $e->getMessage();

      // Check if we've exceeded max retries
      if (\CRM_Paymentprocessingcore_BAO_PaymentWebhook::hasExceededMaxAttempts($attempts)) {
        // Mark as permanent error - no more retries
        \CRM_Paymentprocessingcore_BAO_PaymentWebhook::markPermanentError(
          $webhookId,
          sprintf('Max retries (%d) exceeded. Last error: %s', $attempts, $errorMessage)
        );
      }
      else {
        // Mark for retry with exponential backoff
        \CRM_Paymentprocessingcore_BAO_PaymentWebhook::markForRetry(
          $webhookId,
          $attempts,
          $errorMessage
        );
      }

      \Civi::log()->error('Webhook processing failed', [
        'webhook_id' => $webhookId,
        'processor_type' => $webhook['processor_type'],
        'event_type' => $webhook['event_type'],
        'error' => $errorMessage,
        'attempts' => $attempts,
        'will_retry' => !(\CRM_Paymentprocessingcore_BAO_PaymentWebhook::hasExceededMaxAttempts($attempts)),
      ]);

      // Return TRUE to continue processing other tasks
      return TRUE;
    }
  }

  /**
   * Callback when queue processing completes.
   *
   * @param \CRM_Queue_TaskContext $ctx Queue task context
   */
  public static function onEnd(\CRM_Queue_TaskContext $ctx): void {
    \Civi::log()->info('Webhook queue processing completed');
  }

}
