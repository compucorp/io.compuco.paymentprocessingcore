<?php

namespace Civi\Paymentprocessingcore\Service;

/**
 * Service for managing webhook processing queues.
 *
 * Creates and manages CiviCRM SQL queues for webhook processing.
 * Each processor type has its own queue to allow independent processing
 * and monitoring.
 *
 * Queue names follow the pattern:
 * io.compuco.paymentprocessingcore.webhook.{processor_type}
 *
 * @package Civi\Paymentprocessingcore\Service
 */
class WebhookQueueService {

  /**
   * Queue name prefix for webhook processing queues.
   */
  private const QUEUE_NAME_PREFIX = 'io.compuco.paymentprocessingcore.webhook.';

  /**
   * Track which queues have been initialized in this request.
   *
   * @var array<string, bool>
   */
  private static array $initialized = [];

  /**
   * Get or create a queue for a specific processor type.
   *
   * Uses CiviCRM's queue system with SQL backend for persistence.
   * The queue is reset once per test run to ensure test isolation.
   *
   * @param string $processorType The processor type (e.g., 'stripe', 'gocardless')
   *
   * @return \CRM_Queue_Queue The queue instance
   */
  public function getQueue(string $processorType): \CRM_Queue_Queue {
    $queueName = self::QUEUE_NAME_PREFIX . $processorType;

    // Only reset on first access during tests
    $shouldReset = defined('CIVICRM_UF') && CIVICRM_UF === 'UnitTests'
      && !isset(self::$initialized[$queueName]);

    if ($shouldReset) {
      self::$initialized[$queueName] = TRUE;
    }

    return \Civi::queue($queueName, [
      'type' => 'Sql',
      'reset' => $shouldReset,
    ]);
  }

  /**
   * Add a webhook to the processing queue.
   *
   * Creates a queue task that will be processed by WebhookQueueRunnerService.
   * The task stores the webhook ID and additional parameters needed for
   * processing.
   *
   * @param string $processorType The processor type (e.g., 'stripe', 'gocardless')
   * @param int $webhookId ID of the civicrm_payment_webhook record
   * @param array $params Additional parameters to pass to the handler
   *                      Typically includes 'event_data' with parsed payload
   */
  public function addTask(string $processorType, int $webhookId, array $params = []): void {
    $queue = $this->getQueue($processorType);

    $task = new \CRM_Queue_Task(
      [WebhookQueueRunnerService::class, 'runTask'],
      [$webhookId, $params],
      sprintf('Processing webhook %d for %s', $webhookId, $processorType)
    );

    $queue->createItem($task);
  }

  /**
   * Get the number of items in a processor's queue.
   *
   * @param string $processorType The processor type
   *
   * @return int Number of items in the queue
   */
  public function getQueueCount(string $processorType): int {
    $queue = $this->getQueue($processorType);
    return $queue->numberOfItems();
  }

  /**
   * Get the full queue name for a processor type.
   *
   * @param string $processorType The processor type
   *
   * @return string The full queue name
   */
  public function getQueueName(string $processorType): string {
    return self::QUEUE_NAME_PREFIX . $processorType;
  }

  /**
   * Reset initialization tracking (for testing).
   *
   * Clears the static initialization cache so queues can be reset
   * again in subsequent tests.
   */
  public static function resetInitialization(): void {
    self::$initialized = [];
  }

}
