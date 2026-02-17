<?php

namespace Civi\Paymentprocessingcore\DTO;

/**
 * Reconciliation result for completed payments where core handles completion.
 *
 * Extends ReconcileAttemptResult to provide CompletionData for core's
 * ContributionCompletionService. Handlers return this subclass when they
 * want core to complete the contribution; returning the base class with
 * status 'completed' signals that the handler completed it itself.
 *
 * Design: transactionId is non-nullable — if you create a CompletedReconcileResult,
 * you must provide a transaction ID. Core calls getCompletionData() polymorphically:
 * returns CompletionData from this subclass, NULL from the base class.
 */
class CompletedReconcileResult extends ReconcileAttemptResult {

  /**
   * Completion data for core processing.
   *
   * @var \Civi\Paymentprocessingcore\DTO\CompletionData
   */
  private CompletionData $completionData;

  /**
   * Constructor.
   *
   * @param string $actionTaken
   *   Human-readable description of what happened (e.g., 'PaymentIntent succeeded').
   * @param string $transactionId
   *   Payment processor transaction ID (e.g., Stripe charge ID ch_...).
   * @param float|null $feeAmount
   *   Optional fee amount charged by payment processor.
   */
  public function __construct(
    string $actionTaken,
    string $transactionId,
    ?float $feeAmount = NULL,
  ) {
    parent::__construct('completed', $actionTaken);
    $this->completionData = new CompletionData($transactionId, $feeAmount);
  }

  /**
   * {@inheritdoc}
   */
  public function getCompletionData(): CompletionData {
    return $this->completionData;
  }

}
