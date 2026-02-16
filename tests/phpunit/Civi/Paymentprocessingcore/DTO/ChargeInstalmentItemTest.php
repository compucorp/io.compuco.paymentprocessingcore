<?php

namespace Civi\Paymentprocessingcore\DTO;

/**
 * Tests for ChargeInstalmentItem DTO.
 *
 * @group headless
 */
class ChargeInstalmentItemTest extends \BaseHeadlessTest {

  /**
   * Tests constructor sets all properties correctly.
   */
  public function testConstructorSetsAllProperties(): void {
    $item = new ChargeInstalmentItem(
      contributionId: 123,
      paymentAttemptId: 456,
      recurringContributionId: 789,
      contactId: 100,
      amount: 50.00,
      currency: 'GBP',
      paymentTokenId: 200,
      paymentProcessorId: 300
    );

    $this->assertEquals(123, $item->contributionId);
    $this->assertEquals(456, $item->paymentAttemptId);
    $this->assertEquals(789, $item->recurringContributionId);
    $this->assertEquals(100, $item->contactId);
    $this->assertEquals(50.00, $item->amount);
    $this->assertEquals('GBP', $item->currency);
    $this->assertEquals(200, $item->paymentTokenId);
    $this->assertEquals(300, $item->paymentProcessorId);
  }

  /**
   * Tests readonly properties are accessible.
   */
  public function testReadonlyPropertiesAreAccessible(): void {
    $item = new ChargeInstalmentItem(
      contributionId: 1,
      paymentAttemptId: 2,
      recurringContributionId: 3,
      contactId: 4,
      amount: 100.50,
      currency: 'USD',
      paymentTokenId: 5,
      paymentProcessorId: 6
    );

    // All properties should be accessible without getters.
    $this->assertIsInt($item->contributionId);
    $this->assertIsInt($item->paymentAttemptId);
    $this->assertIsInt($item->recurringContributionId);
    $this->assertIsInt($item->contactId);
    $this->assertIsFloat($item->amount);
    $this->assertIsString($item->currency);
    $this->assertIsInt($item->paymentTokenId);
    $this->assertIsInt($item->paymentProcessorId);
  }

  /**
   * Tests that amount handles different numeric values.
   */
  public function testAmountHandlesDifferentValues(): void {
    $item1 = new ChargeInstalmentItem(1, 2, 3, 4, 0.01, 'GBP', 5, 6);
    $this->assertEquals(0.01, $item1->amount);

    $item2 = new ChargeInstalmentItem(1, 2, 3, 4, 9999.99, 'GBP', 5, 6);
    $this->assertEquals(9999.99, $item2->amount);

    $item3 = new ChargeInstalmentItem(1, 2, 3, 4, 100.00, 'GBP', 5, 6);
    $this->assertEquals(100.00, $item3->amount);
  }

}
