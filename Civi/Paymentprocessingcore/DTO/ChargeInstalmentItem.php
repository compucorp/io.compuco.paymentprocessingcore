<?php

namespace Civi\Paymentprocessingcore\DTO;

/**
 * DTO for a single instalment to charge.
 *
 * Represents all the data needed for a payment processor extension to
 * charge a single instalment contribution. Used as items in the
 * ChargeInstalmentBatchEvent.
 */
class ChargeInstalmentItem {

  /**
   * Constructor.
   *
   * @param int $contributionId
   *   The contribution ID to charge.
   * @param int $paymentAttemptId
   *   The PaymentAttempt ID tracking this charge.
   * @param int $recurringContributionId
   *   The parent recurring contribution ID.
   * @param int $contactId
   *   The contact ID (donor).
   * @param float $amount
   *   The amount to charge (outstanding balance).
   * @param string $currency
   *   The currency code (e.g., 'GBP', 'USD').
   * @param int $paymentTokenId
   *   The payment token ID for the stored payment method.
   * @param int $paymentProcessorId
   *   The payment processor ID.
   */
  public function __construct(
    public readonly int $contributionId,
    public readonly int $paymentAttemptId,
    public readonly int $recurringContributionId,
    public readonly int $contactId,
    public readonly float $amount,
    public readonly string $currency,
    public readonly int $paymentTokenId,
    public readonly int $paymentProcessorId,
  ) {}

}
