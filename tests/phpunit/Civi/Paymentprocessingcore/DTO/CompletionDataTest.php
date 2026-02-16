<?php

namespace Civi\Paymentprocessingcore\DTO;

/**
 * Tests for CompletionData VO.
 *
 * @group headless
 */
class CompletionDataTest extends \BaseHeadlessTest {

  /**
   * Tests constructor sets properties correctly.
   */
  public function testConstructorSetsProperties(): void {
    $data = new CompletionData('ch_test_123', 1.50);

    $this->assertEquals('ch_test_123', $data->transactionId);
    $this->assertEquals(1.50, $data->feeAmount);
  }

  /**
   * Tests that feeAmount defaults to NULL.
   */
  public function testFeeAmountDefaultsToNull(): void {
    $data = new CompletionData('ch_test_456');

    $this->assertEquals('ch_test_456', $data->transactionId);
    $this->assertNull($data->feeAmount);
  }

  /**
   * Tests readonly properties are accessible.
   */
  public function testReadonlyPropertiesAreAccessible(): void {
    $data = new CompletionData('ch_test_789', 2.25);

    $this->assertIsString($data->transactionId);
    $this->assertIsFloat($data->feeAmount);
  }

}
