<?php

namespace Civi\Paymentprocessingcore\Exception;

/**
 * Exception thrown when contribution completion fails.
 *
 * Extends \Exception to provide additional context data.
 */
class ContributionCompletionException extends \Exception {

  /**
   * Additional context data about the error.
   *
   * @var array
   */
  private array $context;

  /**
   * ContributionCompletionException constructor.
   *
   * @param string $message Error message
   * @param array $context Additional context (contribution_id, transaction_id, etc.)
   * @param \Throwable|null $previous Previous exception
   */
  public function __construct(string $message, array $context = [], ?\Throwable $previous = NULL) {
    parent::__construct($message, 0, $previous);
    $this->context = $context;

    // Log the error with context
    \Civi::log()->error('ContributionCompletionException: ' . $message, $context);
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
