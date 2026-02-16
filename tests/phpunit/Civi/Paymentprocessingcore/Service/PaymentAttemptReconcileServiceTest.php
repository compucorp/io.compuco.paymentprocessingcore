<?php

namespace Civi\Paymentprocessingcore\Service;

use Civi\Paymentprocessingcore\DTO\CompletedReconcileResult;
use Civi\Paymentprocessingcore\DTO\ReconcileAttemptResult;
use Civi\Paymentprocessingcore\Event\ReconcilePaymentAttemptBatchEvent;
use CRM_Paymentprocessingcore_BAO_PaymentAttempt as PaymentAttemptBAO;

/**
 * Tests for PaymentAttemptReconcileService.
 *
 * Uses the built-in CiviCRM Dummy payment processor type for testing.
 *
 * @group headless
 */
class PaymentAttemptReconcileServiceTest extends \BaseHeadlessTest {

  private const PROCESSOR_TYPE = 'Dummy';

  /**
   * @var \Civi\Paymentprocessingcore\Service\PaymentAttemptReconcileService
   */
  private PaymentAttemptReconcileService $service;

  /**
   * Captured events from dispatcher.
   *
   * @var array<\Civi\Paymentprocessingcore\Event\ReconcilePaymentAttemptBatchEvent>
   */
  private array $capturedEvents = [];

  /**
   * @var callable
   */
  private $eventListener;

  /**
   * Set up test fixtures.
   */
  public function setUp(): void {
    parent::setUp();
    $this->service = new PaymentAttemptReconcileService();
    $this->capturedEvents = [];

    // Register event listener to capture dispatched events.
    $this->eventListener = function ($event): void {
      if ($event instanceof ReconcilePaymentAttemptBatchEvent) {
        $this->capturedEvents[] = $event;
      }
    };

    \Civi::dispatcher()->addListener(
      ReconcilePaymentAttemptBatchEvent::NAME,
      $this->eventListener
    );
  }

  /**
   * Tear down test fixtures.
   */
  public function tearDown(): void {
    // Remove event listener.
    \Civi::dispatcher()->removeListener(
      ReconcilePaymentAttemptBatchEvent::NAME,
      $this->eventListener
    );
    parent::tearDown();
  }

  // -------------------------------------------------------------------------
  // Selection query tests
  // -------------------------------------------------------------------------

  /**
   * Tests that processing attempts older than threshold are selected.
   */
  public function testSelectsProcessingAttemptsOlderThanThreshold(): void {
    $this->createStuckPaymentAttempt([
      'processor_type' => 'dummy',
      'days_ago' => 5,
    ]);

    $attempts = $this->service->getStuckAttempts(self::PROCESSOR_TYPE, 3, 100);

    $this->assertCount(1, $attempts);
  }

  /**
   * Tests that processing attempts newer than threshold are skipped.
   */
  public function testSkipsProcessingAttemptsNewerThanThreshold(): void {
    $this->createStuckPaymentAttempt([
      'processor_type' => 'dummy',
      'days_ago' => 1,
    ]);

    $attempts = $this->service->getStuckAttempts(self::PROCESSOR_TYPE, 3, 100);

    $this->assertCount(0, $attempts);
  }

  /**
   * Tests that only processing status is selected, not pending.
   */
  public function testSkipsPendingStatus(): void {
    $fixtures = $this->createTestChain();
    PaymentAttemptBAO::create([
      'contribution_id' => $fixtures['contribution_id'],
      'contact_id' => $fixtures['contact_id'],
      'payment_processor_id' => $fixtures['processor_id'],
      'processor_type' => 'dummy',
      'status' => 'pending',
    ]);
    $this->backdatePaymentAttempt($fixtures['contribution_id'], 5);

    $attempts = $this->service->getStuckAttempts(self::PROCESSOR_TYPE, 3, 100);

    $this->assertCount(0, $attempts);
  }

  /**
   * Tests that completed status is not selected.
   */
  public function testSkipsCompletedStatus(): void {
    $fixtures = $this->createTestChain();
    PaymentAttemptBAO::create([
      'contribution_id' => $fixtures['contribution_id'],
      'contact_id' => $fixtures['contact_id'],
      'payment_processor_id' => $fixtures['processor_id'],
      'processor_type' => 'dummy',
      'status' => 'completed',
    ]);
    $this->backdatePaymentAttempt($fixtures['contribution_id'], 5);

    $attempts = $this->service->getStuckAttempts(self::PROCESSOR_TYPE, 3, 100);

    $this->assertCount(0, $attempts);
  }

  /**
   * Tests that failed status is not selected.
   */
  public function testSkipsFailedStatus(): void {
    $fixtures = $this->createTestChain();
    PaymentAttemptBAO::create([
      'contribution_id' => $fixtures['contribution_id'],
      'contact_id' => $fixtures['contact_id'],
      'payment_processor_id' => $fixtures['processor_id'],
      'processor_type' => 'dummy',
      'status' => 'failed',
    ]);
    $this->backdatePaymentAttempt($fixtures['contribution_id'], 5);

    $attempts = $this->service->getStuckAttempts(self::PROCESSOR_TYPE, 3, 100);

    $this->assertCount(0, $attempts);
  }

  /**
   * Tests that cancelled status is not selected.
   */
  public function testSkipsCancelledStatus(): void {
    $fixtures = $this->createTestChain();
    PaymentAttemptBAO::create([
      'contribution_id' => $fixtures['contribution_id'],
      'contact_id' => $fixtures['contact_id'],
      'payment_processor_id' => $fixtures['processor_id'],
      'processor_type' => 'dummy',
      'status' => 'cancelled',
    ]);
    $this->backdatePaymentAttempt($fixtures['contribution_id'], 5);

    $attempts = $this->service->getStuckAttempts(self::PROCESSOR_TYPE, 3, 100);

    $this->assertCount(0, $attempts);
  }

  /**
   * Tests filtering by processor type.
   */
  public function testFiltersByProcessorType(): void {
    // Create a 'dummy' stuck attempt.
    $this->createStuckPaymentAttempt([
      'processor_type' => 'dummy',
      'days_ago' => 5,
    ]);

    // Create a 'gocardless' stuck attempt.
    $this->createStuckPaymentAttempt([
      'processor_type' => 'gocardless',
      'days_ago' => 5,
    ]);

    $attempts = $this->service->getStuckAttempts(self::PROCESSOR_TYPE, 3, 100);

    $this->assertCount(1, $attempts);
    $firstAttempt = reset($attempts);
    $this->assertIsArray($firstAttempt);
    $this->assertEquals('dummy', $firstAttempt['processor_type']);
  }

  /**
   * Tests that limit is respected.
   */
  public function testRespectsLimit(): void {
    for ($i = 0; $i < 5; $i++) {
      $this->createStuckPaymentAttempt([
        'processor_type' => 'dummy',
        'days_ago' => 5 + $i,
      ]);
    }

    $attempts = $this->service->getStuckAttempts(self::PROCESSOR_TYPE, 3, 2);

    $this->assertCount(2, $attempts);
  }

  /**
   * Tests that results are ordered by created_date ascending (oldest first).
   */
  public function testOrdersByCreatedDateAsc(): void {
    $newer = $this->createStuckPaymentAttempt([
      'processor_type' => 'dummy',
      'days_ago' => 4,
    ]);
    $older = $this->createStuckPaymentAttempt([
      'processor_type' => 'dummy',
      'days_ago' => 10,
    ]);

    $attempts = $this->service->getStuckAttempts(self::PROCESSOR_TYPE, 3, 100);
    $attemptIds = array_keys($attempts);

    $this->assertCount(2, $attemptIds);
    // Older should come first.
    $this->assertEquals($older['attempt_id'], $attemptIds[0]);
    $this->assertEquals($newer['attempt_id'], $attemptIds[1]);
  }

  // -------------------------------------------------------------------------
  // Event dispatch tests
  // -------------------------------------------------------------------------

  /**
   * Tests that event is dispatched with correct configuration.
   */
  public function testEventDispatchedWithCorrectConfig(): void {
    $this->createStuckPaymentAttempt([
      'processor_type' => 'dummy',
      'days_ago' => 5,
    ]);

    $this->service->reconcileStuckAttempts([self::PROCESSOR_TYPE => 3], 100);

    $this->assertCount(1, $this->capturedEvents);
    $event = $this->capturedEvents[0];
    $this->assertEquals(self::PROCESSOR_TYPE, $event->getProcessorType());
    $this->assertEquals(3, $event->getThresholdDays());
    $this->assertEquals(100, $event->getRemainingBudget());
  }

  /**
   * Tests that event is dispatched even with zero stuck attempts (OCP for GoCardless).
   */
  public function testEventDispatchedWithZeroAttempts(): void {
    $this->service->reconcileStuckAttempts([self::PROCESSOR_TYPE => 3], 100);

    $this->assertCount(1, $this->capturedEvents);
    $event = $this->capturedEvents[0];
    $this->assertCount(0, $event->getAttempts());
  }

  /**
   * Tests that event contains all PaymentAttempt fields.
   */
  public function testEventContainsAllPaymentAttemptFields(): void {
    $fixtures = $this->createStuckPaymentAttempt([
      'processor_type' => 'dummy',
      'days_ago' => 5,
      'processor_payment_id' => 'pi_test_123',
    ]);

    $this->service->reconcileStuckAttempts([self::PROCESSOR_TYPE => 3], 100);

    $this->assertCount(1, $this->capturedEvents);
    $attempts = $this->capturedEvents[0]->getAttempts();
    $this->assertCount(1, $attempts);

    $attempt = reset($attempts);
    $this->assertIsArray($attempt);
    $this->assertArrayHasKey('id', $attempt);
    $this->assertArrayHasKey('contribution_id', $attempt);
    $this->assertArrayHasKey('contact_id', $attempt);
    $this->assertArrayHasKey('payment_processor_id', $attempt);
    $this->assertArrayHasKey('processor_type', $attempt);
    $this->assertArrayHasKey('processor_payment_id', $attempt);
    $this->assertEquals($fixtures['contribution_id'], $attempt['contribution_id']);
    $this->assertEquals('pi_test_123', $attempt['processor_payment_id']);
  }

  // -------------------------------------------------------------------------
  // Reconciliation result processing tests
  // -------------------------------------------------------------------------

  /**
   * Tests that status is updated to completed when subscriber returns completed.
   */
  public function testStatusUpdatedToCompletedWhenSubscriberReturnsCompleted(): void {
    $fixtures = $this->createStuckPaymentAttempt([
      'processor_type' => 'dummy',
      'days_ago' => 5,
    ]);

    // Add subscriber that marks as completed.
    $subscriber = function (ReconcilePaymentAttemptBatchEvent $event): void {
      foreach ($event->getAttempts() as $attemptId => $attempt) {
        $event->setAttemptResult($attemptId, new ReconcileAttemptResult('completed', 'PI succeeded'));
      }
    };
    \Civi::dispatcher()->addListener(ReconcilePaymentAttemptBatchEvent::NAME, $subscriber, -10);

    try {
      $result = $this->service->reconcileStuckAttempts([self::PROCESSOR_TYPE => 3], 100);

      $this->assertEquals(1, $result['reconciled']);

      // Verify DB status changed.
      $attempt = PaymentAttemptBAO::findByContributionId($fixtures['contribution_id']);
      $this->assertIsArray($attempt);
      $this->assertEquals('completed', $attempt['status']);
    }
    finally {
      \Civi::dispatcher()->removeListener(ReconcilePaymentAttemptBatchEvent::NAME, $subscriber);
    }
  }

  /**
   * Tests that status is updated to failed when subscriber returns failed.
   */
  public function testStatusUpdatedToFailedWhenSubscriberReturnsFailed(): void {
    $fixtures = $this->createStuckPaymentAttempt([
      'processor_type' => 'dummy',
      'days_ago' => 5,
    ]);

    $subscriber = function (ReconcilePaymentAttemptBatchEvent $event): void {
      foreach ($event->getAttempts() as $attemptId => $attempt) {
        $event->setAttemptResult($attemptId, new ReconcileAttemptResult('failed', 'PI failed'));
      }
    };
    \Civi::dispatcher()->addListener(ReconcilePaymentAttemptBatchEvent::NAME, $subscriber, -10);

    try {
      $result = $this->service->reconcileStuckAttempts([self::PROCESSOR_TYPE => 3], 100);

      $this->assertEquals(1, $result['reconciled']);

      $attempt = PaymentAttemptBAO::findByContributionId($fixtures['contribution_id']);
      $this->assertIsArray($attempt);
      $this->assertEquals('failed', $attempt['status']);
    }
    finally {
      \Civi::dispatcher()->removeListener(ReconcilePaymentAttemptBatchEvent::NAME, $subscriber);
    }
  }

  /**
   * Tests that status is updated to cancelled when subscriber returns cancelled.
   */
  public function testStatusUpdatedToCancelledWhenSubscriberReturnsCancelled(): void {
    $fixtures = $this->createStuckPaymentAttempt([
      'processor_type' => 'dummy',
      'days_ago' => 5,
    ]);

    $subscriber = function (ReconcilePaymentAttemptBatchEvent $event): void {
      foreach ($event->getAttempts() as $attemptId => $attempt) {
        $event->setAttemptResult($attemptId, new ReconcileAttemptResult('cancelled', 'PI cancelled'));
      }
    };
    \Civi::dispatcher()->addListener(ReconcilePaymentAttemptBatchEvent::NAME, $subscriber, -10);

    try {
      $result = $this->service->reconcileStuckAttempts([self::PROCESSOR_TYPE => 3], 100);

      $this->assertEquals(1, $result['reconciled']);

      $attempt = PaymentAttemptBAO::findByContributionId($fixtures['contribution_id']);
      $this->assertIsArray($attempt);
      $this->assertEquals('cancelled', $attempt['status']);
    }
    finally {
      \Civi::dispatcher()->removeListener(ReconcilePaymentAttemptBatchEvent::NAME, $subscriber);
    }
  }

  /**
   * Tests that status is not updated when subscriber returns unchanged.
   */
  public function testStatusNotUpdatedWhenSubscriberReturnsUnchanged(): void {
    $fixtures = $this->createStuckPaymentAttempt([
      'processor_type' => 'dummy',
      'days_ago' => 5,
    ]);

    $subscriber = function (ReconcilePaymentAttemptBatchEvent $event): void {
      foreach ($event->getAttempts() as $attemptId => $attempt) {
        $event->setAttemptResult($attemptId, new ReconcileAttemptResult('unchanged', 'PI still processing'));
      }
    };
    \Civi::dispatcher()->addListener(ReconcilePaymentAttemptBatchEvent::NAME, $subscriber, -10);

    try {
      $result = $this->service->reconcileStuckAttempts([self::PROCESSOR_TYPE => 3], 100);

      $this->assertEquals(0, $result['reconciled']);
      $this->assertEquals(1, $result['unchanged']);

      // Verify DB status NOT changed.
      $attempt = PaymentAttemptBAO::findByContributionId($fixtures['contribution_id']);
      $this->assertIsArray($attempt);
      $this->assertEquals('processing', $attempt['status']);
    }
    finally {
      \Civi::dispatcher()->removeListener(ReconcilePaymentAttemptBatchEvent::NAME, $subscriber);
    }
  }

  /**
   * Tests unhandled count when no subscriber responds.
   */
  public function testUnhandledCountWhenNoSubscriberResponds(): void {
    $this->createStuckPaymentAttempt([
      'processor_type' => 'dummy',
      'days_ago' => 5,
    ]);

    $result = $this->service->reconcileStuckAttempts([self::PROCESSOR_TYPE => 3], 100);

    $this->assertEquals(0, $result['reconciled']);
    $this->assertEquals(1, $result['unhandled']);
  }

  // -------------------------------------------------------------------------
  // Error handling tests
  // -------------------------------------------------------------------------

  /**
   * Tests that error in one attempt does not stop the batch.
   */
  public function testErrorInOneAttemptDoesNotStopBatch(): void {
    $first = $this->createStuckPaymentAttempt([
      'processor_type' => 'dummy',
      'days_ago' => 10,
    ]);
    $second = $this->createStuckPaymentAttempt([
      'processor_type' => 'dummy',
      'days_ago' => 5,
    ]);

    // Subscriber that gives the first attempt a bogus status (via reflection
    // to bypass DTO validation) so BAO::validateStatus() throws, and gives
    // the second attempt a valid result.
    $subscriber = function (ReconcilePaymentAttemptBatchEvent $event) use ($first): void {
      $bogusResult = $this->createBogusResult();

      foreach ($event->getAttempts() as $attemptId => $attempt) {
        if ($attemptId === $first['attempt_id']) {
          $event->setAttemptResult($attemptId, $bogusResult);
        }
        else {
          $event->setAttemptResult($attemptId, new ReconcileAttemptResult('completed', 'PI succeeded'));
        }
      }
    };
    \Civi::dispatcher()->addListener(ReconcilePaymentAttemptBatchEvent::NAME, $subscriber, -10);

    try {
      $result = $this->service->reconcileStuckAttempts([self::PROCESSOR_TYPE => 3], 100);

      // First attempt should error, second should succeed.
      $this->assertEquals(1, $result['errored']);
      $this->assertEquals(1, $result['reconciled']);

      // Second attempt status should be updated.
      $attempt = PaymentAttemptBAO::findByContributionId($second['contribution_id']);
      $this->assertIsArray($attempt);
      $this->assertEquals('completed', $attempt['status']);
    }
    finally {
      \Civi::dispatcher()->removeListener(ReconcilePaymentAttemptBatchEvent::NAME, $subscriber);
    }
  }

  /**
   * Tests that errored count is incremented on exception.
   */
  public function testErroredCountInSummary(): void {
    $this->createStuckPaymentAttempt([
      'processor_type' => 'dummy',
      'days_ago' => 5,
    ]);

    // Subscriber that gives a bogus status (via reflection) so BAO
    // validateStatus() throws during result processing.
    $subscriber = function (ReconcilePaymentAttemptBatchEvent $event): void {
      $bogusResult = $this->createBogusResult();

      foreach ($event->getAttempts() as $attemptId => $attempt) {
        $event->setAttemptResult($attemptId, $bogusResult);
      }
    };
    \Civi::dispatcher()->addListener(ReconcilePaymentAttemptBatchEvent::NAME, $subscriber, -10);

    try {
      $result = $this->service->reconcileStuckAttempts([self::PROCESSOR_TYPE => 3], 100);

      $this->assertEquals(1, $result['errored']);
      $this->assertEquals(0, $result['reconciled']);
    }
    finally {
      \Civi::dispatcher()->removeListener(ReconcilePaymentAttemptBatchEvent::NAME, $subscriber);
    }
  }

  // -------------------------------------------------------------------------
  // Multi-processor and budget tests
  // -------------------------------------------------------------------------

  /**
   * Tests that multiple processor types are processed sequentially.
   */
  public function testMultipleProcessorTypesProcessedSequentially(): void {
    $this->createStuckPaymentAttempt([
      'processor_type' => 'dummy',
      'days_ago' => 5,
    ]);

    // Use two processor configs; only Dummy has data.
    $result = $this->service->reconcileStuckAttempts([
      self::PROCESSOR_TYPE => 3,
      'GoCardless' => 7,
    ], 100);

    // Both processor types should be in the summary.
    $this->assertContains(self::PROCESSOR_TYPE, $result['processors_processed']);
    $this->assertContains('GoCardless', $result['processors_processed']);
    $this->assertEquals(1, $result['unhandled']);

    // Two events dispatched (one per processor type).
    $this->assertCount(2, $this->capturedEvents);
  }

  /**
   * Tests that shared batch budget decreases across processors.
   */
  public function testSharedBatchBudgetDecreasesAcrossProcessors(): void {
    // Create 6 stuck attempts for Dummy.
    for ($i = 0; $i < 6; $i++) {
      $this->createStuckPaymentAttempt([
        'processor_type' => 'dummy',
        'days_ago' => 5 + $i,
      ]);
    }

    // Budget of 10, Dummy uses up to 6.
    $result = $this->service->reconcileStuckAttempts([
      self::PROCESSOR_TYPE => 3,
      'GoCardless' => 7,
    ], 10);

    // First event (Dummy) should get budget of 10.
    $this->assertEquals(10, $this->capturedEvents[0]->getRemainingBudget());
    // Dummy has 6 unhandled attempts.
    $this->assertEquals(6, $result['unhandled']);

    // Second event (GoCardless) should get remaining budget = 10 - 6 = 4.
    $this->assertEquals(4, $this->capturedEvents[1]->getRemainingBudget());
  }

  // -------------------------------------------------------------------------
  // Event maxRetryCount tests
  // -------------------------------------------------------------------------

  /**
   * Tests that event receives maxRetryCount from service.
   */
  public function testEventDispatchedWithMaxRetryCount(): void {
    $this->createStuckPaymentAttempt([
      'processor_type' => 'dummy',
      'days_ago' => 5,
    ]);

    $this->service->reconcileStuckAttempts([self::PROCESSOR_TYPE => 3], 100, 5);

    $this->assertCount(1, $this->capturedEvents);
    $this->assertEquals(5, $this->capturedEvents[0]->getMaxRetryCount());
  }

  // -------------------------------------------------------------------------
  // Post-reconciliation pipeline tests
  // -------------------------------------------------------------------------

  /**
   * Tests that CompletedReconcileResult completes the contribution via core pipeline.
   */
  public function testCompletedResultWithCompletionDataCompletesContribution(): void {
    $fixtures = $this->createStuckPaymentAttempt([
      'processor_type' => 'dummy',
      'days_ago' => 5,
    ]);

    $subscriber = function (ReconcilePaymentAttemptBatchEvent $event): void {
      foreach ($event->getAttempts() as $attemptId => $attempt) {
        $event->setAttemptResult($attemptId, new CompletedReconcileResult(
          'PaymentIntent succeeded',
          'ch_test_' . $attemptId,
          1.50
        ));
      }
    };
    \Civi::dispatcher()->addListener(ReconcilePaymentAttemptBatchEvent::NAME, $subscriber, -10);

    try {
      $result = $this->service->reconcileStuckAttempts([self::PROCESSOR_TYPE => 3], 100);

      $this->assertEquals(1, $result['reconciled']);

      // Verify PA status updated.
      $attempt = PaymentAttemptBAO::findByContributionId($fixtures['contribution_id']);
      $this->assertIsArray($attempt);
      $this->assertEquals('completed', $attempt['status']);

      // Verify contribution completed.
      $contribution = \Civi\Api4\Contribution::get(FALSE)
        ->addSelect('contribution_status_id:name', 'trxn_id')
        ->addWhere('id', '=', $fixtures['contribution_id'])
        ->execute()
        ->first();
      $this->assertIsArray($contribution);
      $this->assertEquals('Completed', $contribution['contribution_status_id:name']);
      $this->assertEquals('ch_test_' . $fixtures['attempt_id'], $contribution['trxn_id']);
    }
    finally {
      \Civi::dispatcher()->removeListener(ReconcilePaymentAttemptBatchEvent::NAME, $subscriber);
    }
  }

  /**
   * Tests that base ReconcileAttemptResult('completed') skips contribution completion (opt-out).
   */
  public function testCompletedResultWithoutCompletionDataSkipsCompletion(): void {
    $fixtures = $this->createStuckPaymentAttempt([
      'processor_type' => 'dummy',
      'days_ago' => 5,
    ]);

    $subscriber = function (ReconcilePaymentAttemptBatchEvent $event): void {
      foreach ($event->getAttempts() as $attemptId => $attempt) {
        $event->setAttemptResult($attemptId, new ReconcileAttemptResult('completed', 'Handler completed it'));
      }
    };
    \Civi::dispatcher()->addListener(ReconcilePaymentAttemptBatchEvent::NAME, $subscriber, -10);

    try {
      $result = $this->service->reconcileStuckAttempts([self::PROCESSOR_TYPE => 3], 100);

      $this->assertEquals(1, $result['reconciled']);

      // Verify PA status updated.
      $attempt = PaymentAttemptBAO::findByContributionId($fixtures['contribution_id']);
      $this->assertIsArray($attempt);
      $this->assertEquals('completed', $attempt['status']);

      // Verify contribution NOT completed (still Pending — handler manages it).
      $contribution = \Civi\Api4\Contribution::get(FALSE)
        ->addSelect('contribution_status_id:name')
        ->addWhere('id', '=', $fixtures['contribution_id'])
        ->execute()
        ->first();
      $this->assertIsArray($contribution);
      $this->assertEquals('Pending', $contribution['contribution_status_id:name']);
    }
    finally {
      \Civi::dispatcher()->removeListener(ReconcilePaymentAttemptBatchEvent::NAME, $subscriber);
    }
  }

  /**
   * Tests that failed result increments failure_count on ContributionRecur.
   */
  public function testFailedResultIncrementsFailureCount(): void {
    $fixtures = $this->createStuckPaymentAttempt([
      'processor_type' => 'dummy',
      'days_ago' => 5,
      'failure_count' => 1,
    ]);

    $subscriber = function (ReconcilePaymentAttemptBatchEvent $event): void {
      foreach ($event->getAttempts() as $attemptId => $attempt) {
        $event->setAttemptResult($attemptId, new ReconcileAttemptResult('failed', 'PI requires_payment_method'));
      }
    };
    \Civi::dispatcher()->addListener(ReconcilePaymentAttemptBatchEvent::NAME, $subscriber, -10);

    try {
      $result = $this->service->reconcileStuckAttempts([self::PROCESSOR_TYPE => 3], 100);

      $this->assertEquals(1, $result['reconciled']);

      // Verify failure_count incremented from 1 to 2.
      $recur = \Civi\Api4\ContributionRecur::get(FALSE)
        ->addSelect('failure_count')
        ->addWhere('id', '=', $fixtures['recur_id'])
        ->execute()
        ->first();
      $this->assertIsArray($recur);
      $this->assertEquals(2, $recur['failure_count']);
    }
    finally {
      \Civi::dispatcher()->removeListener(ReconcilePaymentAttemptBatchEvent::NAME, $subscriber);
    }
  }

  /**
   * Tests that failed result marks contribution as Failed when threshold exceeded.
   */
  public function testFailedResultMarksContributionFailedWhenThresholdExceeded(): void {
    // failure_count=3, maxRetryCount=3 => after increment: 4 > 3 => mark Failed.
    $fixtures = $this->createStuckPaymentAttempt([
      'processor_type' => 'dummy',
      'days_ago' => 5,
      'failure_count' => 3,
    ]);

    $subscriber = function (ReconcilePaymentAttemptBatchEvent $event): void {
      foreach ($event->getAttempts() as $attemptId => $attempt) {
        $event->setAttemptResult($attemptId, new ReconcileAttemptResult('failed', 'PI failed'));
      }
    };
    \Civi::dispatcher()->addListener(ReconcilePaymentAttemptBatchEvent::NAME, $subscriber, -10);

    try {
      $result = $this->service->reconcileStuckAttempts([self::PROCESSOR_TYPE => 3], 100, 3);

      $this->assertEquals(1, $result['reconciled']);

      // Verify contribution marked as Failed.
      $contribution = \Civi\Api4\Contribution::get(FALSE)
        ->addSelect('contribution_status_id:name')
        ->addWhere('id', '=', $fixtures['contribution_id'])
        ->execute()
        ->first();
      $this->assertIsArray($contribution);
      $this->assertEquals('Failed', $contribution['contribution_status_id:name']);
    }
    finally {
      \Civi::dispatcher()->removeListener(ReconcilePaymentAttemptBatchEvent::NAME, $subscriber);
    }
  }

  /**
   * Tests that failed result does NOT mark pay-later contribution as Failed.
   */
  public function testFailedResultDoesNotMarkPayLaterContributionFailed(): void {
    // failure_count=3, maxRetryCount=3, but is_pay_later => should NOT mark Failed.
    $fixtures = $this->createStuckPaymentAttempt([
      'processor_type' => 'dummy',
      'days_ago' => 5,
      'failure_count' => 3,
      'is_pay_later' => TRUE,
    ]);

    $subscriber = function (ReconcilePaymentAttemptBatchEvent $event): void {
      foreach ($event->getAttempts() as $attemptId => $attempt) {
        $event->setAttemptResult($attemptId, new ReconcileAttemptResult('failed', 'PI failed'));
      }
    };
    \Civi::dispatcher()->addListener(ReconcilePaymentAttemptBatchEvent::NAME, $subscriber, -10);

    try {
      $result = $this->service->reconcileStuckAttempts([self::PROCESSOR_TYPE => 3], 100, 3);

      $this->assertEquals(1, $result['reconciled']);

      // Verify contribution is still Pending (not Failed).
      $contribution = \Civi\Api4\Contribution::get(FALSE)
        ->addSelect('contribution_status_id:name')
        ->addWhere('id', '=', $fixtures['contribution_id'])
        ->execute()
        ->first();
      $this->assertIsArray($contribution);
      $this->assertEquals('Pending', $contribution['contribution_status_id:name']);
    }
    finally {
      \Civi::dispatcher()->removeListener(ReconcilePaymentAttemptBatchEvent::NAME, $subscriber);
    }
  }

  /**
   * Tests that cancelled result marks non-pay-later Pending contribution as Failed.
   */
  public function testCancelledResultMarksNonPayLaterContributionFailed(): void {
    $fixtures = $this->createStuckPaymentAttempt([
      'processor_type' => 'dummy',
      'days_ago' => 5,
    ]);

    $subscriber = function (ReconcilePaymentAttemptBatchEvent $event): void {
      foreach ($event->getAttempts() as $attemptId => $attempt) {
        $event->setAttemptResult($attemptId, new ReconcileAttemptResult('cancelled', 'PI cancelled'));
      }
    };
    \Civi::dispatcher()->addListener(ReconcilePaymentAttemptBatchEvent::NAME, $subscriber, -10);

    try {
      $result = $this->service->reconcileStuckAttempts([self::PROCESSOR_TYPE => 3], 100);

      $this->assertEquals(1, $result['reconciled']);

      // Verify PA status.
      $attempt = PaymentAttemptBAO::findByContributionId($fixtures['contribution_id']);
      $this->assertIsArray($attempt);
      $this->assertEquals('cancelled', $attempt['status']);

      // Verify contribution marked as Failed (Pending + NOT pay-later).
      $contribution = \Civi\Api4\Contribution::get(FALSE)
        ->addSelect('contribution_status_id:name')
        ->addWhere('id', '=', $fixtures['contribution_id'])
        ->execute()
        ->first();
      $this->assertIsArray($contribution);
      $this->assertEquals('Failed', $contribution['contribution_status_id:name']);
    }
    finally {
      \Civi::dispatcher()->removeListener(ReconcilePaymentAttemptBatchEvent::NAME, $subscriber);
    }
  }

  /**
   * Tests that cancelled result leaves pay-later contribution alone.
   */
  public function testCancelledResultLeavesPayLaterContributionAlone(): void {
    $fixtures = $this->createStuckPaymentAttempt([
      'processor_type' => 'dummy',
      'days_ago' => 5,
      'is_pay_later' => TRUE,
    ]);

    $subscriber = function (ReconcilePaymentAttemptBatchEvent $event): void {
      foreach ($event->getAttempts() as $attemptId => $attempt) {
        $event->setAttemptResult($attemptId, new ReconcileAttemptResult('cancelled', 'PI cancelled'));
      }
    };
    \Civi::dispatcher()->addListener(ReconcilePaymentAttemptBatchEvent::NAME, $subscriber, -10);

    try {
      $result = $this->service->reconcileStuckAttempts([self::PROCESSOR_TYPE => 3], 100);

      $this->assertEquals(1, $result['reconciled']);

      // Verify contribution is still Pending (pay-later exception).
      $contribution = \Civi\Api4\Contribution::get(FALSE)
        ->addSelect('contribution_status_id:name')
        ->addWhere('id', '=', $fixtures['contribution_id'])
        ->execute()
        ->first();
      $this->assertIsArray($contribution);
      $this->assertEquals('Pending', $contribution['contribution_status_id:name']);
    }
    finally {
      \Civi::dispatcher()->removeListener(ReconcilePaymentAttemptBatchEvent::NAME, $subscriber);
    }
  }

  // -------------------------------------------------------------------------
  // Helper methods
  // -------------------------------------------------------------------------

  /**
   * Safely cast a value to int.
   *
   * @param int|float|string|null $value
   *   The value to cast.
   *
   * @phpstan-param mixed $value
   *
   * @return int
   *   The integer value, or 0 if not numeric.
   */
  private static function toInt($value): int {
    return is_numeric($value) ? (int) $value : 0;
  }

  /**
   * Create a ReconcileAttemptResult with an invalid status via reflection.
   *
   * Bypasses DTO constructor validation so BAO::validateStatus() throws
   * during result processing. Used to test per-attempt error handling.
   *
   * @return \Civi\Paymentprocessingcore\DTO\ReconcileAttemptResult
   */
  private function createBogusResult(): ReconcileAttemptResult {
    $reflection = new \ReflectionClass(ReconcileAttemptResult::class);
    $result = $reflection->newInstanceWithoutConstructor();

    $statusProp = $reflection->getProperty('status');
    $statusProp->setValue($result, 'bogus_invalid_status');

    $actionProp = $reflection->getProperty('actionTaken');
    $actionProp->setValue($result, 'test error trigger');

    return $result;
  }

  /**
   * Create a complete test chain: contact -> processor -> token -> recur -> contribution.
   *
   * @param array<string, mixed> $options
   *   Options: is_pay_later (bool).
   *
   * @phpstan-param array{is_pay_later?: bool} $options
   *
   * @return array<string, int>
   *   Fixture IDs: contact_id, processor_id, token_id, recur_id, contribution_id.
   */
  private function createTestChain(array $options = []): array {
    $isPayLater = !empty($options['is_pay_later']);
    $contactId = $this->createContact();
    $processorId = $this->createPaymentProcessor();
    $tokenId = $this->createPaymentToken($contactId, $processorId);
    $recurId = $this->createRecurringContribution($contactId, $processorId, $tokenId);
    $contributionId = $this->createContribution($contactId, $recurId, $isPayLater);

    return [
      'contact_id' => $contactId,
      'processor_id' => $processorId,
      'token_id' => $tokenId,
      'recur_id' => $recurId,
      'contribution_id' => $contributionId,
    ];
  }

  /**
   * Create a stuck PaymentAttempt with full test chain.
   *
   * @param array<string, mixed> $options
   *   Options:
   *   - processor_type: (string) e.g., 'dummy' (default: 'dummy')
   *   - days_ago: (int) How many days ago to backdate created_date (default: 5)
   *   - processor_payment_id: (string|null) Processor payment ID (default: null)
   *   - is_pay_later: (bool) Whether to mark contribution as pay-later (default: false)
   *   - failure_count: (int) Initial failure_count on recur (default: 0)
   *
   * @phpstan-param array{processor_type?: string, days_ago?: int, processor_payment_id?: string|null, is_pay_later?: bool, failure_count?: int} $options
   *
   * @return array<string, int>
   *   Fixture IDs: contact_id, processor_id, contribution_id, attempt_id, recur_id.
   */
  private function createStuckPaymentAttempt(array $options = []): array {
    $processorType = $options['processor_type'] ?? 'dummy';
    $daysAgo = $options['days_ago'] ?? 5;
    $processorPaymentId = $options['processor_payment_id'] ?? NULL;
    $isPayLater = $options['is_pay_later'] ?? FALSE;
    $failureCount = $options['failure_count'] ?? 0;

    $chain = $this->createTestChain(['is_pay_later' => $isPayLater]);

    // Set initial failure_count on recur if non-zero.
    if ($failureCount > 0) {
      \Civi\Api4\ContributionRecur::update(FALSE)
        ->addValue('failure_count', $failureCount)
        ->addWhere('id', '=', $chain['recur_id'])
        ->execute();
    }

    $attemptParams = [
      'contribution_id' => $chain['contribution_id'],
      'contact_id' => $chain['contact_id'],
      'payment_processor_id' => $chain['processor_id'],
      'processor_type' => $processorType,
      'status' => 'processing',
    ];

    if ($processorPaymentId !== NULL) {
      $attemptParams['processor_payment_id'] = $processorPaymentId;
    }

    $attempt = PaymentAttemptBAO::create($attemptParams);
    $attemptId = $attempt !== NULL ? self::toInt($attempt->id) : 0;

    // Backdate created_date (needed because threshold check uses created_date).
    $this->backdatePaymentAttempt($chain['contribution_id'], $daysAgo);

    return [
      'contact_id' => $chain['contact_id'],
      'processor_id' => $chain['processor_id'],
      'contribution_id' => $chain['contribution_id'],
      'recur_id' => $chain['recur_id'],
      'attempt_id' => $attemptId,
    ];
  }

  /**
   * Backdate a PaymentAttempt's created_date.
   *
   * Uses raw SQL to set the created_date for threshold testing.
   *
   * @param int $contributionId
   *   The contribution ID linked to the attempt.
   * @param int $daysAgo
   *   Number of days to backdate.
   */
  private function backdatePaymentAttempt(int $contributionId, int $daysAgo): void {
    $timestamp = strtotime("-{$daysAgo} days");
    if ($timestamp === FALSE) {
      throw new \RuntimeException("Failed to compute backdated date for {$daysAgo} days ago");
    }
    $backdatedDate = date('Y-m-d H:i:s', $timestamp);
    \CRM_Core_DAO::executeQuery(
      'UPDATE civicrm_payment_attempt SET created_date = %1 WHERE contribution_id = %2',
      [
        1 => [$backdatedDate, 'String'],
        2 => [$contributionId, 'Integer'],
      ]
    );
  }

  /**
   * Create a contact.
   *
   * @return int
   */
  private function createContact(): int {
    $contact = \Civi\Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Test')
      ->addValue('last_name', 'User')
      ->execute()
      ->first();

    if (!is_array($contact) || !isset($contact['id'])) {
      throw new \RuntimeException('Failed to create contact');
    }

    return self::toInt($contact['id']);
  }

  /**
   * Create a payment processor using Dummy type.
   *
   * @return int
   */
  private function createPaymentProcessor(): int {
    $processor = \Civi\Api4\PaymentProcessor::create(FALSE)
      ->addValue('name', 'Test Processor ' . uniqid())
      ->addValue('payment_processor_type_id:name', self::PROCESSOR_TYPE)
      ->addValue('class_name', 'Payment_Dummy')
      ->addValue('is_active', TRUE)
      ->addValue('is_test', FALSE)
      ->addValue('domain_id', 1)
      ->execute()
      ->first();

    if (!is_array($processor) || !isset($processor['id'])) {
      throw new \RuntimeException('Failed to create payment processor');
    }

    return self::toInt($processor['id']);
  }

  /**
   * Create a payment token.
   *
   * @param int $contactId
   * @param int $processorId
   *
   * @return int
   */
  private function createPaymentToken(int $contactId, int $processorId): int {
    $token = \Civi\Api4\PaymentToken::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('payment_processor_id', $processorId)
      ->addValue('token', 'tok_test_' . uniqid())
      ->addValue('created_date', date('Y-m-d H:i:s'))
      ->execute()
      ->first();

    if (!is_array($token) || !isset($token['id'])) {
      throw new \RuntimeException('Failed to create payment token');
    }

    return self::toInt($token['id']);
  }

  /**
   * Create a recurring contribution.
   *
   * @param int $contactId
   * @param int $processorId
   * @param int $tokenId
   *
   * @return int
   */
  private function createRecurringContribution(int $contactId, int $processorId, int $tokenId): int {
    $recur = \Civi\Api4\ContributionRecur::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('payment_processor_id', $processorId)
      ->addValue('payment_token_id', $tokenId)
      ->addValue('amount', 50.00)
      ->addValue('currency', 'GBP')
      ->addValue('financial_type_id:name', 'Donation')
      ->addValue('frequency_unit:name', 'month')
      ->addValue('frequency_interval', 1)
      ->addValue('start_date', date('Y-m-d', strtotime('-6 months')))
      ->addValue('contribution_status_id:name', 'In Progress')
      ->execute()
      ->first();

    if (!is_array($recur) || !isset($recur['id'])) {
      throw new \RuntimeException('Failed to create recurring contribution');
    }

    return self::toInt($recur['id']);
  }

  /**
   * Create a contribution.
   *
   * @param int $contactId
   *   The contact ID.
   * @param int $recurId
   *   The recurring contribution ID.
   * @param bool $isPayLater
   *   Whether to mark the contribution as pay-later.
   *
   * @return int
   *   The contribution ID.
   */
  private function createContribution(int $contactId, int $recurId, bool $isPayLater = FALSE): int {
    $create = \Civi\Api4\Contribution::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('contribution_recur_id', $recurId)
      ->addValue('financial_type_id:name', 'Donation')
      ->addValue('total_amount', 50.00)
      ->addValue('currency', 'GBP')
      ->addValue('contribution_status_id:name', 'Pending')
      ->addValue('receive_date', date('Y-m-d', strtotime('-1 day')));

    if ($isPayLater) {
      $create->addValue('is_pay_later', TRUE);
    }

    $contribution = $create->execute()->first();

    if (!is_array($contribution) || !isset($contribution['id'])) {
      throw new \RuntimeException('Failed to create contribution');
    }

    return self::toInt($contribution['id']);
  }

}
