<?php

namespace Civi\Paymentprocessingcore\Payability;

/**
 * Unit tests for PayabilityResult DTO.
 *
 * @group headless
 */
class PayabilityResultTest extends \BaseHeadlessTest {

  /**
   * Test constructor sets all properties correctly.
   */
  public function testConstructorSetsPropertiesCorrectly() {
    $result = new PayabilityResult(
      TRUE,
      'Test reason',
      'one_off',
      ['key' => 'value']
    );

    $this->assertTrue($result->canPayNow);
    $this->assertEquals('Test reason', $result->reason);
    $this->assertEquals('one_off', $result->paymentType);
    $this->assertEquals(['key' => 'value'], $result->metadata);
  }

  /**
   * Test constructor defaults.
   */
  public function testConstructorDefaults() {
    $result = new PayabilityResult(FALSE, 'Reason only');

    $this->assertFalse($result->canPayNow);
    $this->assertEquals('Reason only', $result->reason);
    $this->assertNull($result->paymentType);
    $this->assertEquals([], $result->metadata);
  }

  /**
   * Test canPay() factory method with defaults.
   */
  public function testCanPayFactoryMethodWithDefaults() {
    $result = PayabilityResult::canPay();

    $this->assertTrue($result->canPayNow);
    $this->assertEquals('User can initiate payment', $result->reason);
    $this->assertEquals('one_off', $result->paymentType);
    $this->assertEquals([], $result->metadata);
  }

  /**
   * Test canPay() factory method with custom values.
   */
  public function testCanPayFactoryMethodWithCustomValues() {
    $result = PayabilityResult::canPay(
      'Custom reason',
      'custom_type',
      ['mandate_id' => 'MD123']
    );

    $this->assertTrue($result->canPayNow);
    $this->assertEquals('Custom reason', $result->reason);
    $this->assertEquals('custom_type', $result->paymentType);
    $this->assertEquals(['mandate_id' => 'MD123'], $result->metadata);
  }

  /**
   * Test cannotPay() factory method.
   */
  public function testCannotPayFactoryMethod() {
    $result = PayabilityResult::cannotPay(
      'Managed by subscription',
      'subscription',
      ['subscription_id' => 'SU123']
    );

    $this->assertFalse($result->canPayNow);
    $this->assertEquals('Managed by subscription', $result->reason);
    $this->assertEquals('subscription', $result->paymentType);
    $this->assertEquals(['subscription_id' => 'SU123'], $result->metadata);
  }

  /**
   * Test cannotPay() factory method with minimal args.
   */
  public function testCannotPayFactoryMethodWithMinimalArgs() {
    $result = PayabilityResult::cannotPay('Reason only');

    $this->assertFalse($result->canPayNow);
    $this->assertEquals('Reason only', $result->reason);
    $this->assertNull($result->paymentType);
    $this->assertEquals([], $result->metadata);
  }

  /**
   * Test toArray() returns correct structure.
   */
  public function testToArrayReturnsCorrectStructure() {
    $result = new PayabilityResult(
      TRUE,
      'User can pay',
      'one_off',
      ['processor_id' => 1]
    );

    $array = $result->toArray();

    $this->assertEquals([
      'can_pay_now' => TRUE,
      'payability_reason' => 'User can pay',
      'payment_type' => 'one_off',
      'payability_metadata' => ['processor_id' => 1],
    ], $array);
  }

  /**
   * Test toArray() handles null payment type.
   */
  public function testToArrayHandlesNullPaymentType() {
    $result = PayabilityResult::cannotPay('Unknown payment type');

    $array = $result->toArray();

    $this->assertNull($array['payment_type']);
    $this->assertFalse($array['can_pay_now']);
  }

  /**
   * Test toArray() handles empty metadata.
   */
  public function testToArrayHandlesEmptyMetadata() {
    $result = PayabilityResult::canPay();

    $array = $result->toArray();

    $this->assertEquals([], $array['payability_metadata']);
  }

}
