<?php

namespace Civi\Paymentprocessingcore\Helper;

use PHPUnit\Framework\TestCase;

/**
 * Tests for DebugLoggerTrait.
 *
 * @group headless
 */
class DebugLoggerTraitTest extends TestCase {

  /**
   * Test class that uses the trait.
   *
   * @var \Civi\Paymentprocessingcore\Helper\DebugLoggerTraitTestHelper
   */
  private DebugLoggerTraitTestHelper $testClass;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->testClass = new DebugLoggerTraitTestHelper();
    $this->testClass->resetCache();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->testClass->resetCache();
    parent::tearDown();
  }

  /**
   * Test that sensitive keys are identified correctly.
   *
   * @dataProvider sensitiveKeyProvider
   */
  public function testIsSensitiveKey(string $key, bool $expected): void {
    $this->assertEquals($expected, $this->testClass->testIsSensitiveKey($key));
  }

  /**
   * Data provider for sensitive key tests.
   *
   * @return array<string, array{0: string, 1: bool}>
   *   Test cases.
   */
  public function sensitiveKeyProvider(): array {
    return [
      'api_key' => ['api_key', TRUE],
      'API_KEY' => ['API_KEY', TRUE],
      'stripe_api_key' => ['stripe_api_key', TRUE],
      'secret' => ['secret', TRUE],
      'client_secret' => ['client_secret', TRUE],
      'token' => ['token', TRUE],
      'access_token' => ['access_token', TRUE],
      'password' => ['password', TRUE],
      'card_number' => ['card_number', TRUE],
      'cvv' => ['cvv', TRUE],
      'email' => ['email', TRUE],
      'user_email' => ['user_email', TRUE],
      'webhook_id' => ['webhook_id', FALSE],
      'contribution_id' => ['contribution_id', FALSE],
      'amount' => ['amount', FALSE],
      'status' => ['status', FALSE],
    ];
  }

  /**
   * Test that Stripe API key patterns are detected.
   *
   * @dataProvider sensitivePatternProvider
   */
  public function testContainsSensitivePattern(string $value, bool $expected): void {
    $this->assertEquals($expected, $this->testClass->testContainsSensitivePattern($value));
  }

  /**
   * Data provider for sensitive pattern tests.
   *
   * @return array<string, array{0: string, 1: bool}>
   *   Test cases.
   */
  public function sensitivePatternProvider(): array {
    return [
      'stripe_secret_key' => ['sk_test_abc123', TRUE],
      'stripe_publishable_key' => ['pk_live_xyz789', TRUE],
      'stripe_restricted_key' => ['rk_test_def456', TRUE],
      'credit_card_number' => ['4111111111111111', TRUE],
      'payment_intent_id' => ['pi_1234567890', FALSE],
      'charge_id' => ['ch_abcdefghij', FALSE],
      'normal_string' => ['hello world', FALSE],
      'short_number' => ['123456', FALSE],
    ];
  }

  /**
   * Test that sensitive data is filtered from context.
   */
  public function testFilterSensitiveData(): void {
    $context = [
      'webhook_id' => 123,
      'api_key' => 'sk_test_secret123',
      'amount' => 100.00,
      'nested' => [
        'password' => 'secret_password',
        'status' => 'completed',
      ],
      'card_value' => '4111111111111111',
    ];

    $filtered = $this->testClass->testFilterSensitiveData($context);

    $this->assertEquals(123, $filtered['webhook_id']);
    $this->assertEquals('[REDACTED]', $filtered['api_key']);
    $this->assertEquals(100.00, $filtered['amount']);
    $this->assertIsArray($filtered['nested']);
    $this->assertEquals('[REDACTED]', $filtered['nested']['password']);
    $this->assertEquals('completed', $filtered['nested']['status']);
    $this->assertEquals('[REDACTED]', $filtered['card_value']);
  }

  /**
   * Test that non-sensitive data passes through unchanged.
   */
  public function testFilterSensitiveDataPreservesNonSensitive(): void {
    $context = [
      'webhook_id' => 456,
      'contribution_id' => 789,
      'amount' => 50.00,
      'currency' => 'GBP',
      'status' => 'succeeded',
      'payment_intent_id' => 'pi_test123',
    ];

    $filtered = $this->testClass->testFilterSensitiveData($context);

    $this->assertEquals($context, $filtered);
  }

}
