<?php

namespace Civi\Paymentprocessingcore\Payability;

/**
 * Data Transfer Object for payability check results.
 *
 * This class represents the result of checking whether a contribution
 * can be paid now. It contains:
 *
 * - `canPayNow`: Whether the contribution can be paid immediately
 * - `reason`: Human-readable explanation of the payability status
 * - `paymentType`: Type of payment (one_off, subscription, payment_plan)
 * - `metadata`: Processor-specific information (mandate info, subscription ID, etc.)
 *
 * @package Civi\Paymentprocessingcore\Payability
 */
class PayabilityResult {

  /**
   * Whether the contribution can be paid now.
   *
   * - TRUE: User can initiate payment (e.g., via checkout flow)
   * - FALSE: Payment is managed by the processor (subscription, payment plan)
   *
   * @var bool
   */
  public bool $canPayNow;

  /**
   * Human-readable explanation of the payability status.
   *
   * Examples:
   * - "User can initiate payment via checkout"
   * - "Managed by GoCardless subscription"
   * - "Managed by Direct Debit payment plan"
   *
   * @var string
   */
  public string $reason;

  /**
   * Type of payment this contribution belongs to.
   *
   * Possible values:
   * - 'one_off': Single payment, not linked to recurring
   * - 'subscription': Part of a recurring subscription (auto-managed)
   * - 'payment_plan': Part of a payment plan with membership (auto-managed)
   *
   * @var string|null
   */
  public ?string $paymentType;

  /**
   * Processor-specific metadata.
   *
   * Contains additional information relevant to the payment processor,
   * such as mandate status, subscription ID, customer ID, etc.
   *
   * @var array<string, mixed>
   */
  public array $metadata;

  /**
   * Construct a PayabilityResult.
   *
   * @param bool $canPayNow
   *   Whether the contribution can be paid now.
   * @param string $reason
   *   Human-readable explanation of the status.
   * @param string|null $paymentType
   *   Type of payment (one_off, subscription, payment_plan).
   * @param array<string, mixed> $metadata
   *   Processor-specific metadata.
   */
  public function __construct(
    bool $canPayNow,
    string $reason,
    ?string $paymentType = NULL,
    array $metadata = []
  ) {
    $this->canPayNow = $canPayNow;
    $this->reason = $reason;
    $this->paymentType = $paymentType;
    $this->metadata = $metadata;
  }

  /**
   * Create a result indicating the contribution can be paid now.
   *
   * @param string $reason
   *   Explanation of why it can be paid.
   * @param string|null $paymentType
   *   Type of payment.
   * @param array<string, mixed> $metadata
   *   Additional metadata.
   *
   * @return self
   */
  public static function canPay(
    string $reason = 'User can initiate payment',
    ?string $paymentType = 'one_off',
    array $metadata = []
  ): self {
    return new self(TRUE, $reason, $paymentType, $metadata);
  }

  /**
   * Create a result indicating the contribution cannot be paid now.
   *
   * @param string $reason
   *   Explanation of why it cannot be paid.
   * @param string|null $paymentType
   *   Type of payment.
   * @param array<string, mixed> $metadata
   *   Additional metadata.
   *
   * @return self
   */
  public static function cannotPay(
    string $reason,
    ?string $paymentType = NULL,
    array $metadata = []
  ): self {
    return new self(FALSE, $reason, $paymentType, $metadata);
  }

  /**
   * Convert to array for API responses.
   *
   * @return array<string, mixed>
   */
  public function toArray(): array {
    return [
      'can_pay_now' => $this->canPayNow,
      'payability_reason' => $this->reason,
      'payment_type' => $this->paymentType,
      'payability_metadata' => $this->metadata,
    ];
  }

}
