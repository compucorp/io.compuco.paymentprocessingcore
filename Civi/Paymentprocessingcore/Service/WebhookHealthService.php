<?php

namespace Civi\Paymentprocessingcore\Service;

use CRM_Paymentprocessingcore_BAO_PaymentWebhook as PaymentWebhook;

/**
 * Service for calculating webhook health metrics.
 *
 * Provides health status calculations used by both the health endpoint
 * and SearchKit dashboards. Designed for SaaS platform monitoring where
 * platform operators need visibility into webhook processing health.
 *
 * @package Civi\Paymentprocessingcore\Service
 */
class WebhookHealthService {

  /**
   * Threshold for "degraded" status: pending webhooks older than this (minutes).
   */
  public const DEGRADED_PENDING_AGE_MINUTES = 10;

  /**
   * Threshold for "unhealthy" status: pending webhooks older than this (minutes).
   */
  public const UNHEALTHY_PENDING_AGE_MINUTES = 30;

  /**
   * Threshold for "stuck" status: processing webhooks older than this (minutes).
   */
  public const STUCK_THRESHOLD_MINUTES = 30;

  /**
   * Health status: System is operating normally.
   */
  public const STATUS_HEALTHY = 'healthy';

  /**
   * Health status: System is operational but experiencing issues.
   */
  public const STATUS_DEGRADED = 'degraded';

  /**
   * Health status: System has critical issues requiring attention.
   */
  public const STATUS_UNHEALTHY = 'unhealthy';

  /**
   * Get complete health status for all webhook processors.
   *
   * @return array<string,mixed>
   *   Health status array with status, processors, totals, thresholds.
   */
  public function getHealthStatus(): array {
    $processors = $this->getProcessorStats();
    $totals = $this->getTotals();
    $oldestPending = $this->getOldestPendingAgeMinutes();
    $lastProcessed = $this->getLastProcessedAt();

    $stuckExceedsLimit = $totals['stuck'] >= PaymentWebhook::MAX_STUCK_RESET_LIMIT;

    return [
      'status' => $this->calculateOverallStatus($totals, $oldestPending, $stuckExceedsLimit),
      'processors' => $processors,
      'totals' => $totals,
      'thresholds' => [
        'stuck_reset_limit' => PaymentWebhook::MAX_STUCK_RESET_LIMIT,
        'stuck_exceeds_limit' => $stuckExceedsLimit,
      ],
      'oldest_pending_age_minutes' => $oldestPending,
      'last_processed_at' => $lastProcessed,
    ];
  }

  /**
   * Get webhook statistics grouped by processor type.
   *
   * @return array<string, array{pending: int, stuck: int, errors: int}>
   */
  public function getProcessorStats(): array {
    $stuckThreshold = self::STUCK_THRESHOLD_MINUTES;
    $sql = "SELECT
              processor_type,
              SUM(CASE WHEN status IN ('new', 'processing') THEN 1 ELSE 0 END) as pending,
              SUM(CASE WHEN status = 'processing'
                  AND processing_started_at IS NOT NULL
                  AND processing_started_at < DATE_SUB(NOW(), INTERVAL {$stuckThreshold} MINUTE) THEN 1 ELSE 0 END) as stuck,
              SUM(CASE WHEN status IN ('error', 'permanent_error') THEN 1 ELSE 0 END) as errors
            FROM civicrm_payment_webhook
            GROUP BY processor_type";

    /** @var \CRM_Core_DAO $dao */
    $dao = \CRM_Core_DAO::executeQuery($sql);
    $result = [];

    while ($dao->fetch()) {
      /** @var string $processorType */
      $processorType = $dao->processor_type;
      $result[$processorType] = [
        'pending' => (int) $dao->pending,
        'stuck' => (int) $dao->stuck,
        'errors' => (int) $dao->errors,
      ];
    }

    return $result;
  }

  /**
   * Get aggregate totals across all processors.
   *
   * @return array{pending: int, stuck: int, permanent_errors: int, processed_last_hour: int}
   */
  public function getTotals(): array {
    $stuckThreshold = self::STUCK_THRESHOLD_MINUTES;
    $sql = "SELECT
              SUM(CASE WHEN status IN ('new', 'processing') THEN 1 ELSE 0 END) as pending,
              SUM(CASE WHEN status = 'processing'
                  AND processing_started_at IS NOT NULL
                  AND processing_started_at < DATE_SUB(NOW(), INTERVAL {$stuckThreshold} MINUTE) THEN 1 ELSE 0 END) as stuck,
              SUM(CASE WHEN status = 'permanent_error' THEN 1 ELSE 0 END) as permanent_errors,
              SUM(CASE WHEN status = 'processed'
                  AND processed_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 1 ELSE 0 END) as processed_last_hour
            FROM civicrm_payment_webhook";

    /** @var \CRM_Core_DAO $dao */
    $dao = \CRM_Core_DAO::executeQuery($sql);
    $dao->fetch();

    return [
      'pending' => (int) ($dao->pending ?? 0),
      'stuck' => (int) ($dao->stuck ?? 0),
      'permanent_errors' => (int) ($dao->permanent_errors ?? 0),
      'processed_last_hour' => (int) ($dao->processed_last_hour ?? 0),
    ];
  }

  /**
   * Get the age in minutes of the oldest pending webhook.
   *
   * @return int|null Age in minutes, or null if no pending webhooks.
   */
  public function getOldestPendingAgeMinutes(): ?int {
    $sql = "SELECT TIMESTAMPDIFF(MINUTE, MIN(created_date), NOW()) as age_minutes
            FROM civicrm_payment_webhook
            WHERE status IN ('new', 'processing')";

    $result = \CRM_Core_DAO::singleValueQuery($sql);

    return $result !== NULL ? (int) $result : NULL;
  }

  /**
   * Get the timestamp of the most recently processed webhook.
   *
   * @return string|null ISO 8601 formatted timestamp, or null if none processed.
   */
  public function getLastProcessedAt(): ?string {
    $sql = "SELECT MAX(processed_at) FROM civicrm_payment_webhook WHERE status = 'processed'";
    $result = \CRM_Core_DAO::singleValueQuery($sql);

    if ($result) {
      return (new \DateTime($result))->format(\DateTime::ATOM);
    }

    return NULL;
  }

  /**
   * Calculate overall health status based on metrics.
   *
   * Status logic:
   * - unhealthy: stuck > 0 OR stuck exceeds limit OR pending age > 30min
   * - degraded: permanent_errors > 0 OR pending age > 10min
   * - healthy: all else
   *
   * @param array{pending: int, stuck: int, permanent_errors: int, processed_last_hour: int} $totals
   * @param int|null $oldestPendingAge Age of oldest pending webhook in minutes.
   * @param bool $stuckExceedsLimit Whether stuck count exceeds reset limit.
   *
   * @return string One of: healthy, degraded, unhealthy
   */
  private function calculateOverallStatus(array $totals, ?int $oldestPendingAge, bool $stuckExceedsLimit): string {
    // Unhealthy conditions.
    if ($stuckExceedsLimit) {
      return self::STATUS_UNHEALTHY;
    }
    if ($totals['stuck'] > 0) {
      return self::STATUS_UNHEALTHY;
    }
    if ($oldestPendingAge !== NULL && $oldestPendingAge >= self::UNHEALTHY_PENDING_AGE_MINUTES) {
      return self::STATUS_UNHEALTHY;
    }

    // Degraded conditions.
    if ($totals['permanent_errors'] > 0) {
      return self::STATUS_DEGRADED;
    }
    if ($oldestPendingAge !== NULL && $oldestPendingAge >= self::DEGRADED_PENDING_AGE_MINUTES) {
      return self::STATUS_DEGRADED;
    }

    return self::STATUS_HEALTHY;
  }

}
