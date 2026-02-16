<?php

namespace Civi\Paymentprocessingcore\DTO;

/**
 * Immutable Value Object grouping contribution completion details.
 *
 * Used by CompletedReconcileResult to provide transaction details
 * for core's ContributionCompletionService.
 */
class CompletionData {

  /**
   * Constructor.
   *
   * @param string $transactionId
   *   Payment processor transaction ID (e.g., Stripe charge ID ch_...).
   * @param float|null $feeAmount
   *   Optional fee amount charged by payment processor.
   */
  public function __construct(
    public readonly string $transactionId,
    public readonly ?float $feeAmount = NULL,
  ) {}

}
