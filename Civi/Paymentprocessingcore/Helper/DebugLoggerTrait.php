<?php

namespace Civi\Paymentprocessingcore\Helper;

/**
 * Trait for debug logging that only logs when CiviCRM debug mode is enabled.
 *
 * This trait provides consistent debug logging across payment processing
 * extensions (Stripe, PaymentProcessingCore, etc.). Debug logs are only
 * written when CiviCRM's "Enable Debugging" setting is ON.
 *
 * Usage:
 *   use \Civi\Paymentprocessingcore\Helper\DebugLoggerTrait;
 *   $this->logDebug('ClassName::methodName: Description', ['key' => 'value']);
 *
 * Sensitive data (API keys, tokens, card numbers) is automatically filtered.
 */
trait DebugLoggerTrait {

  /**
   * Cached debug enabled state.
   *
   * @var bool|null
   */
  private static ?bool $debugEnabled = NULL;

  /**
   * Sensitive keys that should be redacted from log context.
   *
   * @var array<string>
   */
  private static array $sensitiveKeys = [
    'api_key',
    'secret',
    'token',
    'password',
    'card_number',
    'cvv',
    'cvc',
    'exp_month',
    'exp_year',
    'account_number',
    'routing_number',
    'publishable_key',
    'secret_key',
    'webhook_secret',
    'client_secret',
    'email',
    'phone',
  ];

  /**
   * Check if debug mode is enabled.
   *
   * @return bool
   *   TRUE if debug mode is enabled.
   */
  protected function isDebugEnabled(): bool {
    if (self::$debugEnabled === NULL) {
      self::$debugEnabled = (bool) \Civi::settings()->get('debug_enabled');
    }
    return self::$debugEnabled;
  }

  /**
   * Log a debug message if debug mode is enabled.
   *
   * @param string $message
   *   The debug message (typically "ClassName::methodName: Description").
   * @param array<string|int, mixed> $context
   *   Optional context data to include in the log.
   * @param string $channel
   *   Optional log channel. Defaults to 'paymentprocessing'.
   */
  protected function logDebug(string $message, array $context = [], string $channel = 'paymentprocessing'): void {
    if (!$this->isDebugEnabled()) {
      return;
    }
    $filtered = $this->filterSensitiveData($context);
    \Civi::log($channel)->debug('[DEBUG] ' . $message, $filtered);
  }

  /**
   * Filter sensitive data from context array.
   *
   * @param array<string|int, mixed> $context
   *   The context array to filter.
   *
   * @return array<string|int, mixed>
   *   The filtered context array with sensitive values redacted.
   */
  private function filterSensitiveData(array $context): array {
    $filtered = [];
    foreach ($context as $key => $value) {
      $stringKey = (string) $key;
      if (is_array($value)) {
        $filtered[$key] = $this->filterSensitiveData($value);
      }
      elseif ($this->isSensitiveKey($stringKey)) {
        $filtered[$key] = '[REDACTED]';
      }
      elseif (is_string($value) && $this->containsSensitivePattern($value)) {
        $filtered[$key] = '[REDACTED]';
      }
      else {
        $filtered[$key] = $value;
      }
    }
    return $filtered;
  }

  /**
   * Check if a key is sensitive.
   *
   * @param string $key
   *   The key to check.
   *
   * @return bool
   *   TRUE if the key is sensitive.
   */
  private function isSensitiveKey(string $key): bool {
    $lowerKey = strtolower($key);
    foreach (self::$sensitiveKeys as $sensitiveKey) {
      if (str_contains($lowerKey, $sensitiveKey)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Check if a value contains sensitive patterns.
   *
   * @param string $value
   *   The value to check.
   *
   * @return bool
   *   TRUE if the value contains sensitive patterns.
   */
  private function containsSensitivePattern(string $value): bool {
    // Stripe API keys start with sk_ or pk_
    if (preg_match('/^(sk_|pk_|rk_)[a-zA-Z0-9]+/', $value)) {
      return TRUE;
    }
    // Credit card patterns (13-19 digits)
    if (preg_match('/\b\d{13,19}\b/', $value)) {
      return TRUE;
    }
    return FALSE;
  }

}
