<?php

namespace Civi\Paymentprocessingcore\Event;

use Civi\Paymentprocessingcore\DTO\ReconcileAttemptResult;

/**
 * Tests for ReconcilePaymentAttemptBatchEvent and ReconcileAttemptResult.
 *
 * @group headless
 */
class ReconcilePaymentAttemptBatchEventTest extends \BaseHeadlessTest {

  /**
   * Tests constructor sets all properties correctly.
   */
  public function testConstructorSetsAllProperties(): void {
    $attempts = [
      1 => ['id' => 1, 'status' => 'processing', 'processor_type' => 'stripe'],
      2 => ['id' => 2, 'status' => 'processing', 'processor_type' => 'stripe'],
    ];

    $event = new ReconcilePaymentAttemptBatchEvent('Stripe', $attempts, 3, 100, 5);

    $this->assertEquals('Stripe', $event->getProcessorType());
    $this->assertCount(2, $event->getAttempts());
    $this->assertEquals(3, $event->getThresholdDays());
    $this->assertEquals(100, $event->getRemainingBudget());
    $this->assertEquals(5, $event->getMaxRetryCount());
  }

  /**
   * Tests getProcessorType returns correct value.
   */
  public function testGetProcessorTypeReturnsCorrectValue(): void {
    $event = new ReconcilePaymentAttemptBatchEvent('GoCardless', [], 7, 50);

    $this->assertEquals('GoCardless', $event->getProcessorType());
  }

  /**
   * Tests getThresholdDays returns correct value.
   */
  public function testGetThresholdDaysReturnsCorrectValue(): void {
    $event = new ReconcilePaymentAttemptBatchEvent('Stripe', [], 5, 100);

    $this->assertEquals(5, $event->getThresholdDays());
  }

  /**
   * Tests getRemainingBudget returns correct value.
   */
  public function testGetRemainingBudgetReturnsCorrectValue(): void {
    $event = new ReconcilePaymentAttemptBatchEvent('Stripe', [], 3, 42);

    $this->assertEquals(42, $event->getRemainingBudget());
  }

  /**
   * Tests getAttempts returns keyed array with all fields present.
   */
  public function testGetAttemptsReturnsKeyedArray(): void {
    $attempts = [
      10 => [
        'id' => 10,
        'contribution_id' => 100,
        'contact_id' => 200,
        'payment_processor_id' => 5,
        'processor_type' => 'stripe',
        'processor_session_id' => 'cs_test',
        'processor_payment_id' => 'pi_test',
        'status' => 'processing',
        'created_date' => '2026-01-01 00:00:00',
        'updated_date' => '2026-01-01 00:00:00',
      ],
    ];

    $event = new ReconcilePaymentAttemptBatchEvent('Stripe', $attempts, 3, 100);

    $returnedAttempts = $event->getAttempts();
    $this->assertArrayHasKey(10, $returnedAttempts);
    $this->assertEquals(100, $returnedAttempts[10]['contribution_id']);
    $this->assertEquals('pi_test', $returnedAttempts[10]['processor_payment_id']);
  }

  // -------------------------------------------------------------------------
  // Result collection tests
  // -------------------------------------------------------------------------

  /**
   * Tests setAttemptResult with a valid result.
   */
  public function testSetAttemptResultWithValidResult(): void {
    $attempts = [1 => ['id' => 1, 'status' => 'processing']];
    $event = new ReconcilePaymentAttemptBatchEvent('Stripe', $attempts, 3, 100);

    $result = new ReconcileAttemptResult('completed', 'PaymentIntent succeeded');
    $event->setAttemptResult(1, $result);

    $results = $event->getAttemptResults();
    $this->assertCount(1, $results);
    $this->assertArrayHasKey(1, $results);
    $this->assertSame($result, $results[1]);
    $this->assertEquals('completed', $results[1]->status);
    $this->assertEquals('PaymentIntent succeeded', $results[1]->actionTaken);
  }

  /**
   * Tests setAttemptResult throws for unknown attempt ID.
   */
  public function testSetAttemptResultThrowsForUnknownAttemptId(): void {
    $attempts = [1 => ['id' => 1, 'status' => 'processing']];
    $event = new ReconcilePaymentAttemptBatchEvent('Stripe', $attempts, 3, 100);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Attempt ID 999 is not in the attempts array');

    $event->setAttemptResult(999, new ReconcileAttemptResult('completed', 'test'));
  }

  /**
   * Tests hasAttemptResult returns true when result is set.
   */
  public function testHasAttemptResultReturnsTrueWhenSet(): void {
    $attempts = [1 => ['id' => 1, 'status' => 'processing']];
    $event = new ReconcilePaymentAttemptBatchEvent('Stripe', $attempts, 3, 100);

    $event->setAttemptResult(1, new ReconcileAttemptResult('completed', 'test'));

    $this->assertTrue($event->hasAttemptResult(1));
  }

  /**
   * Tests hasAttemptResult returns false when result is not set.
   */
  public function testHasAttemptResultReturnsFalseWhenNotSet(): void {
    $attempts = [1 => ['id' => 1, 'status' => 'processing']];
    $event = new ReconcilePaymentAttemptBatchEvent('Stripe', $attempts, 3, 100);

    $this->assertFalse($event->hasAttemptResult(1));
  }

  /**
   * Tests getAttemptResults returns all results when multiple are set.
   */
  public function testGetAttemptResultsReturnsAllResults(): void {
    $attempts = [
      1 => ['id' => 1, 'status' => 'processing'],
      2 => ['id' => 2, 'status' => 'processing'],
      3 => ['id' => 3, 'status' => 'processing'],
    ];
    $event = new ReconcilePaymentAttemptBatchEvent('Stripe', $attempts, 3, 100);

    $event->setAttemptResult(1, new ReconcileAttemptResult('completed', 'PI succeeded'));
    $event->setAttemptResult(2, new ReconcileAttemptResult('failed', 'PI failed'));
    $event->setAttemptResult(3, new ReconcileAttemptResult('unchanged', 'PI still processing'));

    $results = $event->getAttemptResults();
    $this->assertCount(3, $results);
    $this->assertEquals('completed', $results[1]->status);
    $this->assertEquals('failed', $results[2]->status);
    $this->assertEquals('unchanged', $results[3]->status);
  }

  // -------------------------------------------------------------------------
  // Edge case tests
  // -------------------------------------------------------------------------

  /**
   * Tests event works with empty attempts array.
   */
  public function testEmptyAttemptsArray(): void {
    $event = new ReconcilePaymentAttemptBatchEvent('Stripe', [], 3, 100);

    $this->assertEquals('Stripe', $event->getProcessorType());
    $this->assertCount(0, $event->getAttempts());
    $this->assertEmpty($event->getAttemptResults());
  }

  /**
   * Tests event name constant.
   */
  public function testEventNameConstant(): void {
    $this->assertEquals(
      'paymentprocessingcore.reconcile_payment_attempt_batch',
      ReconcilePaymentAttemptBatchEvent::NAME
    );
  }

  // -------------------------------------------------------------------------
  // ReconcileAttemptResult Value Object tests
  // -------------------------------------------------------------------------

  /**
   * Tests all valid statuses are accepted.
   */
  public function testValidStatusesAccepted(): void {
    $statuses = ['completed', 'failed', 'cancelled', 'unchanged'];

    foreach ($statuses as $status) {
      $result = new ReconcileAttemptResult($status, 'test action');
      $this->assertEquals($status, $result->status);
      $this->assertEquals('test action', $result->actionTaken);
    }
  }

  /**
   * Tests that invalid status throws exception.
   */
  public function testInvalidStatusThrowsException(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Invalid status "invalid"');

    /** @phpstan-ignore argument.type */
    new ReconcileAttemptResult('invalid', 'test action');
  }

  /**
   * Tests getMaxRetryCount returns correct value.
   */
  public function testGetMaxRetryCountReturnsCorrectValue(): void {
    $event = new ReconcilePaymentAttemptBatchEvent('Stripe', [], 3, 100, 5);

    $this->assertEquals(5, $event->getMaxRetryCount());
  }

  /**
   * Tests getMaxRetryCount defaults to 3 when not specified.
   */
  public function testGetMaxRetryCountDefaultsToThree(): void {
    $event = new ReconcilePaymentAttemptBatchEvent('Stripe', [], 3, 100);

    $this->assertEquals(3, $event->getMaxRetryCount());
  }

  /**
   * Tests getCompletionData returns NULL on base ReconcileAttemptResult.
   */
  public function testGetCompletionDataReturnsNullByDefault(): void {
    $result = new ReconcileAttemptResult('completed', 'test action');

    $this->assertNull($result->getCompletionData());
  }

}
