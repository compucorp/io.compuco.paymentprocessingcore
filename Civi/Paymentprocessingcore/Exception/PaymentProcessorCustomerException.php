<?php

namespace Civi\Paymentprocessingcore\Exception;

/**
 * Exception thrown when payment processor customer operations fail.
 */
class PaymentProcessorCustomerException extends \Exception {

  /**
   * Additional context data about the error.
   *
   * @var array
   */
  private array $context;

  /**
   * PaymentProcessorCustomerException constructor.
   *
   * @param string $message Error message
   * @param array $context Additional context (contact_id, payment_processor_id, etc.)
   * @param \Throwable|null $previous Previous exception
   */
  public function __construct(string $message, array $context = [], ?\Throwable $previous = NULL) {
    parent::__construct($message, 0, $previous);
    $this->context = $context;

    // Log the error with context
    \Civi::log()->error('PaymentProcessorCustomerException: ' . $message, $context);
  }

  /**
   * Get error context data.
   *
   * @return array
   */
  public function getContext(): array {
    return $this->context;
  }

}
