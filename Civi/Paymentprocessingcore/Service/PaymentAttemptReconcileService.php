<?php

namespace Civi\Paymentprocessingcore\Service;

use Civi\Paymentprocessingcore\DTO\ReconcileAttemptResult;
use Civi\Paymentprocessingcore\Event\ReconcilePaymentAttemptBatchEvent;
use Civi\Paymentprocessingcore\Helper\DebugLoggerTrait;
use CRM_Paymentprocessingcore_BAO_PaymentAttempt as PaymentAttemptBAO;

/**
 * Service to reconcile stuck payment attempts.
 *
 * Finds PaymentAttempt records stuck in 'processing' status, dispatches
 * Symfony events for processor-specific extensions to check their APIs,
 * and handles the post-reconciliation pipeline (contribution completion,
 * failure counting, status transitions).
 *
 * Two integration patterns:
 * - PaymentAttempt-based (standard): Handler calls setAttemptResult(). Core
 *   handles post-reconciliation (completion, failure counts, status marking).
 * - Custom-query (opt-out): Handler uses event as trigger + config only.
 *   Never calls setAttemptResult(). Core takes no post-reconciliation action.
 */
class PaymentAttemptReconcileService {

  use DebugLoggerTrait;

  /**
   * Lazily-loaded ContributionCompletionService.
   *
   * @var \Civi\Paymentprocessingcore\Service\ContributionCompletionService|null
   */
  private ?ContributionCompletionService $completionService = NULL;

  /**
   * Reconcile stuck payment attempts across multiple processor types.
   *
   * Processes each processor type sequentially. The batch budget is shared
   * across all processors (not per-processor).
   *
   * @param array $processorConfigs
   *   Processor type to threshold days mapping (e.g., ['Stripe' => 3, 'GoCardless' => 7]).
   * @param int $batchSize
   *   Maximum total number of attempts to reconcile across all processors.
   * @param int $maxRetryCount
   *   Maximum number of retries before marking a recurring contribution as failed.
   *
   * @return array
   *   Summary of processing results.
   *
   * @phpstan-param array<string, int> $processorConfigs
   * @phpstan-return array{reconciled: int, unchanged: int, errored: int, unhandled: int, processors_processed: array<string>, message: string}
   */
  public function reconcileStuckAttempts(array $processorConfigs, int $batchSize, int $maxRetryCount = 3): array {
    $this->logDebug('PaymentAttemptReconcileService::reconcileStuckAttempts: Starting', [
      'processorConfigs' => $processorConfigs,
      'batchSize' => $batchSize,
      'maxRetryCount' => $maxRetryCount,
    ]);

    $totalSummary = [
      'reconciled' => 0,
      'unchanged' => 0,
      'errored' => 0,
      'unhandled' => 0,
      'processors_processed' => [],
    ];

    $remainingBudget = $batchSize;

    foreach ($processorConfigs as $processorType => $thresholdDays) {
      if ($remainingBudget <= 0) {
        break;
      }

      $result = $this->reconcileByProcessor($processorType, $thresholdDays, $remainingBudget, $maxRetryCount);

      $totalSummary['reconciled'] += $result['reconciled'];
      $totalSummary['unchanged'] += $result['unchanged'];
      $totalSummary['errored'] += $result['errored'];
      $totalSummary['unhandled'] += $result['unhandled'];
      $totalSummary['processors_processed'][] = $processorType;

      $remainingBudget -= ($result['reconciled'] + $result['unchanged'] + $result['errored'] + $result['unhandled']);

      $this->logDebug('PaymentAttemptReconcileService::reconcileStuckAttempts: Processor completed', [
        'processorType' => $processorType,
        'result' => $result,
        'remainingBudget' => $remainingBudget,
      ]);
    }

    $totalSummary['message'] = sprintf(
      'Processed %d processor type(s): %d reconciled, %d unchanged, %d errored, %d unhandled.',
      count($totalSummary['processors_processed']),
      $totalSummary['reconciled'],
      $totalSummary['unchanged'],
      $totalSummary['errored'],
      $totalSummary['unhandled']
    );

    $this->logDebug('PaymentAttemptReconcileService::reconcileStuckAttempts: Completed', $totalSummary);

    return $totalSummary;
  }

  /**
   * Get stuck payment attempts for a specific processor type.
   *
   * @param string $processorType
   *   Payment processor type name (e.g., 'Stripe').
   * @param int $thresholdDays
   *   Number of days an attempt must be stuck before selection.
   * @param int $limit
   *   Maximum number of attempts to return.
   *
   * @return array
   *   Stuck PaymentAttempt records keyed by attempt ID.
   *
   * @phpstan-return array<int, array<string, mixed>>
   */
  public function getStuckAttempts(string $processorType, int $thresholdDays, int $limit): array {
    $timestamp = strtotime("-{$thresholdDays} days");
    if ($timestamp === FALSE) {
      return [];
    }
    $cutoffDate = date('Y-m-d H:i:s', $timestamp);

    $attempts = \Civi\Api4\PaymentAttempt::get(FALSE)
      ->addSelect(
        'id',
        'contribution_id',
        'contact_id',
        'payment_processor_id',
        'processor_type',
        'processor_session_id',
        'processor_payment_id',
        'status',
        'created_date',
        'updated_date'
      )
      ->addWhere('status', '=', 'processing')
      ->addWhere('processor_type', '=', strtolower($processorType))
      ->addWhere('created_date', '<', $cutoffDate)
      ->addOrderBy('created_date', 'ASC')
      ->setLimit($limit)
      ->execute();

    $indexed = [];
    foreach ($attempts as $attempt) {
      if (!is_array($attempt)) {
        continue;
      }
      $id = self::toInt($attempt['id'] ?? 0);
      if ($id > 0) {
        $indexed[$id] = $attempt;
      }
    }

    return $indexed;
  }

  /**
   * Reconcile stuck attempts for a single processor type.
   *
   * @param string $processorType
   *   Payment processor type name.
   * @param int $thresholdDays
   *   Days before an attempt is considered stuck.
   * @param int $limit
   *   Maximum number of attempts to process.
   * @param int $maxRetryCount
   *   Maximum number of retries before marking a recurring contribution as failed.
   *
   * @return array
   *   Summary for this processor.
   *
   * @phpstan-return array{reconciled: int, unchanged: int, errored: int, unhandled: int}
   */
  private function reconcileByProcessor(string $processorType, int $thresholdDays, int $limit, int $maxRetryCount): array {
    $summary = [
      'reconciled' => 0,
      'unchanged' => 0,
      'errored' => 0,
      'unhandled' => 0,
    ];

    $attempts = $this->getStuckAttempts($processorType, $thresholdDays, $limit);

    $this->logDebug('PaymentAttemptReconcileService::reconcileByProcessor: Found stuck attempts', [
      'processorType' => $processorType,
      'count' => count($attempts),
    ]);

    // Always dispatch event even with 0 attempts (OCP for GoCardless).
    $event = new ReconcilePaymentAttemptBatchEvent(
      $processorType,
      $attempts,
      $thresholdDays,
      $limit,
      $maxRetryCount
    );
    \Civi::dispatcher()->dispatch(ReconcilePaymentAttemptBatchEvent::NAME, $event);

    // Collect attempt IDs that have results for batch pre-fetch.
    $attemptIdsWithResults = [];
    foreach ($attempts as $attemptId => $attemptData) {
      if ($event->hasAttemptResult($attemptId)) {
        $attemptIdsWithResults[$attemptId] = $attemptData;
      }
    }

    // Batch pre-fetch contributions and recurs to avoid N+1 queries.
    $contributionData = [];
    $recurData = [];
    if (!empty($attemptIdsWithResults)) {
      $contributionIds = [];
      foreach ($attemptIdsWithResults as $attemptData) {
        $contribId = self::toInt($attemptData['contribution_id'] ?? 0);
        if ($contribId > 0) {
          $contributionIds[] = $contribId;
        }
      }
      $contributionData = $this->batchFetchContributions($contributionIds);

      $recurIds = [];
      foreach ($contributionData as $contrib) {
        $recurId = self::toInt($contrib['contribution_recur_id'] ?? 0);
        if ($recurId > 0) {
          $recurIds[] = $recurId;
        }
      }
      $recurIds = array_unique($recurIds);
      $recurData = $this->batchFetchRecurs($recurIds);
    }

    // Process results from subscribers.
    foreach ($attempts as $attemptId => $attemptData) {
      try {
        if (!$event->hasAttemptResult($attemptId)) {
          $summary['unhandled']++;
          continue;
        }

        $result = $event->getAttemptResults()[$attemptId];

        if ($result->status === 'unchanged') {
          $summary['unchanged']++;
          continue;
        }

        // Post-reconciliation pipeline.
        $this->processResult($attemptId, $result, $attemptData, $contributionData, $recurData, $maxRetryCount);
        $summary['reconciled']++;

        $this->logDebug('PaymentAttemptReconcileService::reconcileByProcessor: Attempt reconciled', [
          'attemptId' => $attemptId,
          'newStatus' => $result->status,
          'actionTaken' => $result->actionTaken,
        ]);
      }
      catch (\Throwable $e) {
        $summary['errored']++;

        $this->logDebug('PaymentAttemptReconcileService::reconcileByProcessor: Error processing result', [
          'attemptId' => $attemptId,
          'error' => $e->getMessage(),
        ]);
      }
    }

    return $summary;
  }

  /**
   * Process a single reconciliation result through the post-reconciliation pipeline.
   *
   * @param int $attemptId
   *   The PaymentAttempt ID.
   * @param \Civi\Paymentprocessingcore\DTO\ReconcileAttemptResult $result
   *   The reconciliation result from the handler.
   * @param array $attemptData
   *   The PaymentAttempt record.
   * @param array<int, array<string, mixed>> $contributionData
   *   Pre-fetched contributions indexed by contribution_id.
   * @param array<int, array<string, mixed>> $recurData
   *   Pre-fetched recurring contributions indexed by recur_id.
   * @param int $maxRetryCount
   *   Maximum retries before marking recurring contribution as failed.
   *
   * @phpstan-param array<string, mixed> $attemptData
   */
  private function processResult(
    int $attemptId,
    ReconcileAttemptResult $result,
    array $attemptData,
    array $contributionData,
    array $recurData,
    int $maxRetryCount
  ): void {
    switch ($result->status) {
      case 'completed':
        $this->handleCompleted($attemptId, $result, $attemptData, $contributionData);
        break;

      case 'failed':
        $this->handleFailed($attemptId, $attemptData, $contributionData, $recurData, $maxRetryCount);
        break;

      case 'cancelled':
        $this->handleCancelled($attemptId, $attemptData, $contributionData);
        break;

      default:
        PaymentAttemptBAO::updateStatus($attemptId, $result->status);
        break;
    }
  }

  /**
   * Handle a completed reconciliation result.
   *
   * Updates PaymentAttempt status, then calls ContributionCompletionService if the result
   * provides CompletionData (polymorphic dispatch).
   *
   * @param int $attemptId
   *   The PaymentAttempt ID.
   * @param \Civi\Paymentprocessingcore\DTO\ReconcileAttemptResult $result
   *   The reconciliation result.
   * @param array $attemptData
   *   The PaymentAttempt record.
   * @param array<int, array<string, mixed>> $contributionData
   *   Pre-fetched contributions indexed by contribution_id.
   *
   * @phpstan-param array<string, mixed> $attemptData
   */
  private function handleCompleted(
    int $attemptId,
    ReconcileAttemptResult $result,
    array $attemptData,
    array $contributionData
  ): void {
    PaymentAttemptBAO::updateStatus($attemptId, 'completed');

    $completionData = $result->getCompletionData();
    if ($completionData === NULL) {
      $this->logDebug('PaymentAttemptReconcileService::handleCompleted: Handler completed contribution itself (opt-out)', [
        'attemptId' => $attemptId,
      ]);
      return;
    }

    $contributionId = self::toInt($attemptData['contribution_id'] ?? 0);
    if ($contributionId <= 0) {
      return;
    }

    $this->getCompletionService()->complete(
      $contributionId,
      $completionData->transactionId,
      $completionData->feeAmount
    );

    $this->logDebug('PaymentAttemptReconcileService::handleCompleted: Contribution completed via core pipeline', [
      'attemptId' => $attemptId,
      'contributionId' => $contributionId,
      'transactionId' => $completionData->transactionId,
    ]);
  }

  /**
   * Handle a failed reconciliation result.
   *
   * Updates PaymentAttempt status, increments failure_count on ContributionRecur,
   * and marks the contribution as Failed if threshold is exceeded
   * and the contribution is not pay-later.
   *
   * @param int $attemptId
   *   The PaymentAttempt ID.
   * @param array $attemptData
   *   The PaymentAttempt record.
   * @param array<int, array<string, mixed>> $contributionData
   *   Pre-fetched contributions indexed by contribution_id.
   * @param array<int, array<string, mixed>> $recurData
   *   Pre-fetched recurring contributions indexed by recur_id.
   * @param int $maxRetryCount
   *   Maximum retries before marking recurring contribution as failed.
   *
   * @phpstan-param array<string, mixed> $attemptData
   */
  private function handleFailed(
    int $attemptId,
    array $attemptData,
    array $contributionData,
    array $recurData,
    int $maxRetryCount
  ): void {
    PaymentAttemptBAO::updateStatus($attemptId, 'failed');

    $contributionId = self::toInt($attemptData['contribution_id'] ?? 0);
    if ($contributionId <= 0) {
      return;
    }

    $contribution = $contributionData[$contributionId] ?? NULL;
    if (!is_array($contribution)) {
      return;
    }

    $recurId = self::toInt($contribution['contribution_recur_id'] ?? 0);
    if ($recurId <= 0) {
      return;
    }

    $recur = $recurData[$recurId] ?? NULL;
    if (!is_array($recur)) {
      return;
    }

    $currentFailureCount = self::toInt($recur['failure_count'] ?? 0);
    $newFailureCount = $this->incrementFailureCount($recurId, $currentFailureCount);

    $this->logDebug('PaymentAttemptReconcileService::handleFailed: Incremented failure count', [
      'attemptId' => $attemptId,
      'recurId' => $recurId,
      'newFailureCount' => $newFailureCount,
      'maxRetryCount' => $maxRetryCount,
    ]);

    $isPayLater = !empty($contribution['is_pay_later']);
    if ($newFailureCount > $maxRetryCount && !$isPayLater) {
      $this->markContributionFailed($contributionId);

      $this->logDebug('PaymentAttemptReconcileService::handleFailed: Contribution marked as Failed (threshold exceeded)', [
        'attemptId' => $attemptId,
        'contributionId' => $contributionId,
        'newFailureCount' => $newFailureCount,
        'maxRetryCount' => $maxRetryCount,
      ]);
    }
  }

  /**
   * Handle a cancelled reconciliation result.
   *
   * Updates PaymentAttempt status and marks the contribution as Failed if it is
   * Pending and not pay-later.
   *
   * @param int $attemptId
   *   The PaymentAttempt ID.
   * @param array $attemptData
   *   The PaymentAttempt record.
   * @param array<int, array<string, mixed>> $contributionData
   *   Pre-fetched contributions indexed by contribution_id.
   *
   * @phpstan-param array<string, mixed> $attemptData
   */
  private function handleCancelled(
    int $attemptId,
    array $attemptData,
    array $contributionData
  ): void {
    PaymentAttemptBAO::updateStatus($attemptId, 'cancelled');

    $contributionId = self::toInt($attemptData['contribution_id'] ?? 0);
    if ($contributionId <= 0) {
      return;
    }

    $contribution = $contributionData[$contributionId] ?? NULL;
    if (!is_array($contribution)) {
      return;
    }

    $isPending = ($contribution['contribution_status_id:name'] ?? '') === 'Pending';
    $isPayLater = !empty($contribution['is_pay_later']);

    if ($isPending && !$isPayLater) {
      $this->markContributionFailed($contributionId);

      $this->logDebug('PaymentAttemptReconcileService::handleCancelled: Pending contribution marked as Failed', [
        'attemptId' => $attemptId,
        'contributionId' => $contributionId,
      ]);
    }
  }

  /**
   * Batch fetch contributions by IDs.
   *
   * @param array<int> $contributionIds
   *   Array of contribution IDs.
   *
   * @return array<int, array<string, mixed>>
   *   Contributions indexed by contribution_id.
   */
  private function batchFetchContributions(array $contributionIds): array {
    if (empty($contributionIds)) {
      return [];
    }

    $contributions = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('id', 'contribution_status_id:name', 'is_pay_later', 'contribution_recur_id')
      ->addWhere('id', 'IN', $contributionIds)
      ->execute();

    $indexed = [];
    foreach ($contributions as $contribution) {
      if (!is_array($contribution)) {
        continue;
      }
      $id = self::toInt($contribution['id'] ?? 0);
      if ($id > 0) {
        $indexed[$id] = $contribution;
      }
    }

    return $indexed;
  }

  /**
   * Batch fetch recurring contributions by IDs.
   *
   * @param array<int> $recurIds
   *   Array of recurring contribution IDs.
   *
   * @return array<int, array<string, mixed>>
   *   Recurring contributions indexed by recur_id.
   */
  private function batchFetchRecurs(array $recurIds): array {
    if (empty($recurIds)) {
      return [];
    }

    $recurs = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addSelect('id', 'failure_count')
      ->addWhere('id', 'IN', $recurIds)
      ->execute();

    $indexed = [];
    foreach ($recurs as $recur) {
      if (!is_array($recur)) {
        continue;
      }
      $id = self::toInt($recur['id'] ?? 0);
      if ($id > 0) {
        $indexed[$id] = $recur;
      }
    }

    return $indexed;
  }

  /**
   * Increment failure_count on a ContributionRecur.
   *
   * @param int $recurId
   *   The recurring contribution ID.
   * @param int $currentFailureCount
   *   The current failure count.
   *
   * @return int
   *   The new failure count.
   */
  private function incrementFailureCount(int $recurId, int $currentFailureCount): int {
    $newCount = $currentFailureCount + 1;

    \Civi\Api4\ContributionRecur::update(FALSE)
      ->addValue('failure_count', $newCount)
      ->addWhere('id', '=', $recurId)
      ->execute();

    return $newCount;
  }

  /**
   * Mark a contribution as Failed.
   *
   * @param int $contributionId
   *   The contribution ID.
   */
  private function markContributionFailed(int $contributionId): void {
    \Civi\Api4\Contribution::update(FALSE)
      ->addValue('contribution_status_id:name', 'Failed')
      ->addWhere('id', '=', $contributionId)
      ->execute();
  }

  /**
   * Safely cast a value to int.
   *
   * Used for values from CiviCRM API4 results (array<string, mixed>) which
   * are always numeric but typed as mixed at PHPStan level 9.
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
   * Get the ContributionCompletionService (lazy-loaded).
   *
   * @return \Civi\Paymentprocessingcore\Service\ContributionCompletionService
   */
  private function getCompletionService(): ContributionCompletionService {
    if ($this->completionService === NULL) {
      $service = \Civi::service('paymentprocessingcore.contribution_completion');
      if (!$service instanceof ContributionCompletionService) {
        throw new \RuntimeException('contribution_completion service must return ContributionCompletionService');
      }
      $this->completionService = $service;
    }
    return $this->completionService;
  }

}
