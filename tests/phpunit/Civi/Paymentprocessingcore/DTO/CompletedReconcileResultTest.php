<?php

namespace Civi\Paymentprocessingcore\DTO;

/**
 * Tests for CompletedReconcileResult DTO.
 *
 * @group headless
 */
class CompletedReconcileResultTest extends \BaseHeadlessTest {

  /**
   * Tests constructor sets status to completed.
   */
  public function testConstructorSetsStatusToCompleted(): void {
    $result = new CompletedReconcileResult('PaymentIntent succeeded', 'ch_test_123');

    $this->assertEquals('completed', $result->status);
    $this->assertEquals('PaymentIntent succeeded', $result->actionTaken);
  }

  /**
   * Tests getCompletionData returns CompletionData with transactionId and feeAmount.
   */
  public function testGetCompletionDataReturnsCompletionData(): void {
    $result = new CompletedReconcileResult('PI succeeded', 'ch_test_456', 1.50);

    $completionData = $result->getCompletionData();
    $this->assertInstanceOf(CompletionData::class, $completionData);
    $this->assertEquals('ch_test_456', $completionData->transactionId);
    $this->assertEquals(1.50, $completionData->feeAmount);
  }

  /**
   * Tests transactionId is required (non-nullable, always present).
   */
  public function testTransactionIdIsRequired(): void {
    $result = new CompletedReconcileResult('PI succeeded', 'ch_required');

    $completionData = $result->getCompletionData();
    $this->assertNotEmpty($completionData->transactionId);
    $this->assertEquals('ch_required', $completionData->transactionId);
  }

  /**
   * Tests feeAmount is optional (defaults to NULL).
   */
  public function testFeeAmountIsOptional(): void {
    $result = new CompletedReconcileResult('PI succeeded', 'ch_test_789');

    $completionData = $result->getCompletionData();
    $this->assertNull($completionData->feeAmount);
  }

  /**
   * Tests that CompletedReconcileResult is a subclass of ReconcileAttemptResult.
   */
  public function testIsSubclassOfReconcileAttemptResult(): void {
    $result = new CompletedReconcileResult('PI succeeded', 'ch_test');

    $this->assertInstanceOf(ReconcileAttemptResult::class, $result);
  }

}
