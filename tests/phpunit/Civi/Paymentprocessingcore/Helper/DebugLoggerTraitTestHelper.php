<?php

namespace Civi\Paymentprocessingcore\Helper;

/**
 * Concrete test helper class that uses the DebugLoggerTrait.
 *
 * Extracted to separate file to ensure proper autoloading.
 */
class DebugLoggerTraitTestHelper {
  use DebugLoggerTrait;

  /**
   * Reset debug cache for testing.
   */
  public function resetCache(): void {
    self::$debugEnabled = NULL;
  }

  /**
   * Expose isDebugEnabled for testing.
   */
  public function testIsDebugEnabled(): bool {
    return $this->isDebugEnabled();
  }

  /**
   * Expose filterSensitiveData for testing.
   *
   * @param array<string|int, mixed> $context
   *   The context to filter.
   *
   * @return array<string|int, mixed>
   *   Filtered context.
   */
  public function testFilterSensitiveData(array $context): array {
    return $this->filterSensitiveData($context);
  }

  /**
   * Expose isSensitiveKey for testing.
   *
   * @param string $key
   *   The key to check.
   *
   * @return bool
   *   TRUE if sensitive.
   */
  public function testIsSensitiveKey(string $key): bool {
    return $this->isSensitiveKey($key);
  }

  /**
   * Expose containsSensitivePattern for testing.
   *
   * @param string $value
   *   The value to check.
   *
   * @return bool
   *   TRUE if contains sensitive pattern.
   */
  public function testContainsSensitivePattern(string $value): bool {
    return $this->containsSensitivePattern($value);
  }

}
