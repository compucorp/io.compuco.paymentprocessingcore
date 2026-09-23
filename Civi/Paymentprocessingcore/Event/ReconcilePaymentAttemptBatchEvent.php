<?php

namespace Civi\Paymentprocessingcore\Event;

use Civi\Core\Event\GenericHookEvent;
use Civi\Paymentprocessingcore\DTO\ReconcileAttemptResult;

/**
 * Batch event for reconciling stuck payment attempts.
 *
 * Dispatched once per processor type. Allows processor extensions to check
 * their API for the real status of stuck payments and report results.
 *
 * Two usage patterns:
 * - PaymentAttempt-based (e.g. Stripe): iterate getAttempts() and call
 *   setAttemptResult() for each; the core builds the run summary from those
 *   results.
 * - Custom-query (e.g. GoCardless): use getProcessorType()/getThresholdDays()/
 *   getRemainingBudget() as trigger + config, reconcile own data internally, and
 *   report totals via reportCounts() so they are included in the run summary.
 */
class ReconcilePaymentAttemptBatchEvent extends GenericHookEvent {

  /**
   * Event name constant.
   */
  public const NAME = 'paymentprocessingcore.reconcile_payment_attempt_batch';

  /**
   * Reconciliation results keyed by attempt ID.
   *
   * @var array<int, \Civi\Paymentprocessingcore\DTO\ReconcileAttemptResult>
   */
  private array $results = [];

  /**
   * Counts reported directly by custom-query handlers.
   *
   * @var array{reconciled: int, unchanged: int, errored: int, unhandled: int}
   */
  private array $reportedCounts = [
    'reconciled' => 0,
    'unchanged' => 0,
    'errored' => 0,
    'unhandled' => 0,
  ];

  /**
   * Constructor.
   *
   * @param string $processorType
   *   Processor type name (e.g., 'Stripe', 'GoCardless').
   * @param array $attempts
   *   Array of stuck PaymentAttempt records, keyed by attempt ID.
   * @param int $thresholdDays
   *   Number of days a payment must be stuck before reconciliation.
   * @param int $remainingBudget
   *   Remaining batch budget available for this processor.
   * @param int $maxRetryCount
   *   Maximum number of retries before marking a recurring contribution as failed.
   *
   * @phpstan-param array<int, array<string, mixed>> $attempts
   */
  public function __construct(
    protected string $processorType,
    protected array $attempts,
    protected int $thresholdDays,
    protected int $remainingBudget,
    protected int $maxRetryCount = 3,
  ) {}

  /**
   * Get the processor type.
   *
   * @return string
   *   The processor type name.
   */
  public function getProcessorType(): string {
    return $this->processorType;
  }

  /**
   * Get the stuck payment attempts.
   *
   * @return array
   *   Array of PaymentAttempt records, keyed by attempt ID.
   *
   * @phpstan-return array<int, array<string, mixed>>
   */
  public function getAttempts(): array {
    return $this->attempts;
  }

  /**
   * Get the threshold days for stuck detection.
   *
   * @return int
   *   Number of days before an attempt is considered stuck.
   */
  public function getThresholdDays(): int {
    return $this->thresholdDays;
  }

  /**
   * Get the remaining batch budget.
   *
   * @return int
   *   Remaining number of items that can be processed.
   */
  public function getRemainingBudget(): int {
    return $this->remainingBudget;
  }

  /**
   * Get the maximum retry count.
   *
   * @return int
   *   Maximum number of retries before marking a recurring contribution as failed.
   */
  public function getMaxRetryCount(): int {
    return $this->maxRetryCount;
  }

  /**
   * Set the reconciliation result for a specific attempt.
   *
   * @param int $attemptId
   *   The PaymentAttempt ID.
   * @param \Civi\Paymentprocessingcore\DTO\ReconcileAttemptResult $result
   *   The reconciliation result.
   *
   * @throws \InvalidArgumentException
   *   If the attempt ID is not in the attempts array.
   */
  public function setAttemptResult(int $attemptId, ReconcileAttemptResult $result): void {
    if (!array_key_exists($attemptId, $this->attempts)) {
      throw new \InvalidArgumentException(
        sprintf('Attempt ID %d is not in the attempts array', $attemptId)
      );
    }

    $this->results[$attemptId] = $result;
  }

  /**
   * Get all reconciliation results.
   *
   * @return array<int, \Civi\Paymentprocessingcore\DTO\ReconcileAttemptResult>
   *   Results keyed by attempt ID.
   */
  public function getAttemptResults(): array {
    return $this->results;
  }

  /**
   * Check whether a result has been set for a specific attempt.
   *
   * @param int $attemptId
   *   The PaymentAttempt ID.
   *
   * @return bool
   *   TRUE if a result exists for this attempt.
   */
  public function hasAttemptResult(int $attemptId): bool {
    return array_key_exists($attemptId, $this->results);
  }

  /**
   * Report reconciliation counts directly, for handlers that do not use
   * PaymentAttempt records (the custom-query pattern, e.g. GoCardless).
   *
   * Counts are additive, so a handler may call this more than once. Handlers
   * using setAttemptResult() must not call this — the core counts their results
   * separately, and mixing the two would double-count.
   *
   * @param int $reconciled
   * @param int $unchanged
   * @param int $errored
   * @param int $unhandled
   */
  public function reportCounts(int $reconciled, int $unchanged = 0, int $errored = 0, int $unhandled = 0): void {
    if ($reconciled < 0 || $unchanged < 0 || $errored < 0 || $unhandled < 0) {
      throw new \InvalidArgumentException('Reconciliation counts must be non-negative');
    }

    $this->reportedCounts['reconciled'] += $reconciled;
    $this->reportedCounts['unchanged'] += $unchanged;
    $this->reportedCounts['errored'] += $errored;
    $this->reportedCounts['unhandled'] += $unhandled;
  }

  /**
   * Get the counts reported by custom-query handlers.
   *
   * @return array{reconciled: int, unchanged: int, errored: int, unhandled: int}
   */
  public function getReportedCounts(): array {
    return $this->reportedCounts;
  }

}
