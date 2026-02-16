<?php

namespace Civi\Paymentprocessingcore\DTO;

/**
 * Value Object representing the result of reconciling a single payment attempt.
 *
 * Immutable after construction. Validates status against allowed values.
 * Used by processor-specific handlers to report reconciliation outcomes
 * back to the core ReconcilePaymentAttemptBatchEvent.
 *
 * @phpstan-type ValidStatus 'completed'|'failed'|'cancelled'|'unchanged'
 */
class ReconcileAttemptResult {

  /**
   * Valid reconciliation statuses.
   *
   * @var array<int, string>
   */
  private const VALID_STATUSES = ['completed', 'failed', 'cancelled', 'unchanged'];

  /**
   * Constructor.
   *
   * @param string $status
   *   Reconciliation outcome: completed, failed, cancelled, or unchanged.
   * @param string $actionTaken
   *   Human-readable description of what happened (e.g., 'PaymentIntent succeeded').
   *
   * @phpstan-param ValidStatus $status
   *
   * @throws \InvalidArgumentException
   *   If status is not one of the valid values.
   */
  public function __construct(
    public readonly string $status,
    public readonly string $actionTaken,
  ) {
    if (!in_array($status, self::VALID_STATUSES, TRUE)) {
      throw new \InvalidArgumentException(
        sprintf('Invalid status "%s". Valid: %s', $status, implode(', ', self::VALID_STATUSES))
      );
    }
  }

  /**
   * Returns completion data if core should complete the contribution.
   *
   * Base implementation returns NULL (opt-out). Subclasses override
   * to provide completion data for core processing.
   *
   * @return \Civi\Paymentprocessingcore\DTO\CompletionData|null
   *   Completion data or NULL if handler manages completion itself.
   */
  public function getCompletionData(): ?CompletionData {
    return NULL;
  }

}
