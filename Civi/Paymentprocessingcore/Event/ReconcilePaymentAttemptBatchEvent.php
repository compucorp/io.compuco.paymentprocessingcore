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
 * - Stripe: Uses getAttempts() to iterate PaymentAttempt records, calls
 *   setAttemptResult() for each.
 * - GoCardless: Uses getProcessorType()/getThresholdDays()/getRemainingBudget()
 *   as trigger + config; queries its own data internally.
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

}
