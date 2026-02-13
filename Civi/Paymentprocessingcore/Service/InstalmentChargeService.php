<?php

namespace Civi\Paymentprocessingcore\Service;

use Civi\Paymentprocessingcore\DTO\ChargeInstalmentItem;
use Civi\Paymentprocessingcore\Event\ChargeInstalmentBatchEvent;
use Civi\Paymentprocessingcore\Helper\DebugLoggerTrait;
use CRM_Paymentprocessingcore_BAO_PaymentAttempt as PaymentAttemptBAO;

/**
 * Service to charge due instalment contributions.
 *
 * Selects eligible contributions, creates/manages PaymentAttempt records,
 * and dispatches Symfony events for processor-specific charging.
 *
 * This service handles the core logic while payment processor extensions
 * (Stripe, GoCardless, etc.) subscribe to the ChargeInstalmentBatchEvent
 * to perform the actual payment API calls.
 */
class InstalmentChargeService {

  use DebugLoggerTrait;

  /**
   * Charge due instalments for specified processor types.
   *
   * Processes each processor type sequentially to avoid OOM issues.
   * Each processor type gets its own batch (up to batchSize).
   *
   * @param array<string> $processorTypes
   *   Payment processor type names (e.g., ["Stripe", "GoCardless"]).
   *   Must be explicitly specified - no "all" default.
   * @param int $batchSize
   *   Maximum number of records to process PER processor type.
   * @param int $maxRetryCount
   *   Maximum failure count before skipping recurring contribution.
   *
   * @return array{charged: int, skipped: int, errored: int, processors_processed: array<string>, message: string}
   *   Summary of processing results.
   */
  public function chargeInstalments(array $processorTypes, int $batchSize, int $maxRetryCount): array {

    $this->logDebug('InstalmentChargeService::chargeInstalments: Starting', [
      'processorTypes' => $processorTypes,
      'batchSize' => $batchSize,
      'maxRetryCount' => $maxRetryCount,
    ]);

    $totalSummary = [
      'charged' => 0,
      'skipped' => 0,
      'errored' => 0,
      'processors_processed' => [],
    ];

    // Process each processor type sequentially to avoid OOM.
    foreach ($processorTypes as $processorType) {
      $result = $this->chargeInstalmentsByProcessor($processorType, $batchSize, $maxRetryCount);

      // Aggregate results.
      $totalSummary['charged'] += $result['charged'];
      $totalSummary['skipped'] += $result['skipped'];
      $totalSummary['errored'] += $result['errored'];
      $totalSummary['processors_processed'][] = $processorType;

      $this->logDebug('InstalmentChargeService::chargeInstalments: Processor completed', [
        'processorType' => $processorType,
        'result' => $result,
      ]);

      // Memory is freed after each processor's batch is processed.
    }

    $totalSummary['message'] = sprintf(
      'Processed %d processor type(s): %d charged, %d skipped, %d errors.',
      count($totalSummary['processors_processed']),
      $totalSummary['charged'],
      $totalSummary['skipped'],
      $totalSummary['errored']
    );

    $this->logDebug('InstalmentChargeService::chargeInstalments: Completed', $totalSummary);

    return $totalSummary;
  }

  /**
   * Charge due instalments for a single processor type.
   *
   * @param string $processorType
   *   Payment processor type name (e.g., "Stripe").
   * @param int $batchSize
   *   Maximum number of records to process.
   * @param int $maxRetryCount
   *   Maximum failure count before skipping recurring contribution.
   *
   * @return array{charged: int, skipped: int, errored: int}
   *   Summary of processing results for this processor.
   */
  private function chargeInstalmentsByProcessor(string $processorType, int $batchSize, int $maxRetryCount): array {
    $summary = [
      'charged' => 0,
      'skipped' => 0,
      'errored' => 0,
    ];

    // Step 1: Select contributions to charge.
    $contributions = $this->getEligibleContributions($processorType, $batchSize, $maxRetryCount);

    $this->logDebug('InstalmentChargeService::chargeInstalmentsByProcessor: Found eligible contributions', [
      'processorType' => $processorType,
      'count' => count($contributions),
    ]);

    if (empty($contributions)) {
      return $summary;
    }

    // Step 2: Batch fetch existing PaymentAttempts.
    $contributionIds = array_map(
      static fn($id): int => is_int($id) ? $id : (is_string($id) ? intval($id) : 0),
      array_column($contributions, 'id')
    );
    $existingAttempts = $this->batchFetchPaymentAttempts($contributionIds);

    // Step 3: Prepare batch and create/update PaymentAttempts.
    $items = [];
    foreach ($contributions as $contrib) {
      try {
        // Extract typed values from contribution record.
        $contribId = is_int($contrib['id']) ? $contrib['id'] : 0;
        $totalAmount = is_float($contrib['total_amount']) ? $contrib['total_amount'] : 0.0;
        $paidAmount = is_float($contrib['paid_amount']) ? $contrib['paid_amount'] : 0.0;
        $recurId = is_int($contrib['contribution_recur_id']) ? $contrib['contribution_recur_id'] : 0;
        $contactId = is_int($contrib['contact_id']) ? $contrib['contact_id'] : 0;
        $currency = is_string($contrib['currency']) ? $contrib['currency'] : '';
        $tokenId = is_int($contrib['payment_token_id']) ? $contrib['payment_token_id'] : 0;
        $processorId = is_int($contrib['payment_processor_id']) ? $contrib['payment_processor_id'] : 0;

        // Check existing attempt (in-memory lookup).
        $existing = $existingAttempts[$contribId] ?? NULL;

        // Get or create PaymentAttempt.
        $paymentAttemptId = $this->getOrCreatePaymentAttempt($contrib, $existing, $processorType);
        if ($paymentAttemptId === NULL) {
          $summary['skipped']++;
          continue;
        }

        // Atomic transition pending -> processing.
        if (!PaymentAttemptBAO::updateStatusAtomic($paymentAttemptId, 'pending', 'processing')) {
          // Another worker claimed it.
          $this->logDebug('InstalmentChargeService: Atomic transition failed', [
            'contributionId' => $contribId,
            'paymentAttemptId' => $paymentAttemptId,
          ]);
          $summary['skipped']++;
          continue;
        }

        // Calculate outstanding amount.
        $outstandingAmount = $totalAmount - $paidAmount;

        // Add to batch.
        $items[$contribId] = new ChargeInstalmentItem(
          contributionId: $contribId,
          paymentAttemptId: $paymentAttemptId,
          recurringContributionId: $recurId,
          contactId: $contactId,
          amount: $outstandingAmount,
          currency: $currency,
          paymentTokenId: $tokenId,
          paymentProcessorId: $processorId,
        );

        $summary['charged']++;
      }
      catch (\Throwable $e) {
        $this->logDebug('InstalmentChargeService: Error processing contribution', [
          'contributionId' => $contrib['id'] ?? 0,
          'error' => $e->getMessage(),
        ]);
        $summary['errored']++;
      }
    }

    // Step 4: Dispatch batch event for this processor type.
    if (!empty($items)) {
      $event = new ChargeInstalmentBatchEvent($processorType, $items, $maxRetryCount);
      \Civi::dispatcher()->dispatch(ChargeInstalmentBatchEvent::NAME, $event);

      $this->logDebug('InstalmentChargeService: Dispatched batch event', [
        'processorType' => $processorType,
        'itemCount' => count($items),
        'maxRetryCount' => $maxRetryCount,
      ]);
    }

    return $summary;
  }

  /**
   * Get contributions eligible for charging.
   *
   * Selection criteria:
   * - contribution_status_id IN (Pending, Partially Paid)
   * - total_amount - paid_amount > 0
   * - receive_date <= CURRENT_DATE()
   * - contribution_recur_id IS NOT NULL
   * - Parent recurring: status != Cancelled, payment_token_id IS NOT NULL
   * - contribution_recur.failure_count <= max_retry_count
   * - No PaymentAttempt with status IN ('processing', 'completed', 'cancelled')
   * - Filter by payment_processor_type.name = processorType
   *
   * @param string $processorType
   *   Payment processor type name.
   * @param int $batchSize
   *   Maximum number of records to return.
   * @param int $maxRetryCount
   *   Maximum failure count allowed.
   *
   * @return array<int, array<string, mixed>>
   *   Array of contribution records.
   */
  public function getEligibleContributions(string $processorType, int $batchSize, int $maxRetryCount): array {
    // Get contribution IDs that have blocking PaymentAttempts.
    $blockingAttemptContribIds = $this->getContributionIdsWithBlockingAttempts();

    // Use API4 with explicit joins for multi-level relationships.
    // Implicit FK paths don't work reliably for 4+ level deep joins.
    $query = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect(
        'id',
        'contact_id',
        'total_amount',
        'paid_amount',
        'currency',
        'contribution_recur_id',
        'cr.payment_token_id',
        'pt.payment_processor_id'
      )
      ->addJoin(
        'ContributionRecur AS cr',
        'INNER',
        NULL,
        ['contribution_recur_id', '=', 'cr.id']
      )
      ->addJoin(
        'PaymentToken AS pt',
        'INNER',
        NULL,
        ['cr.payment_token_id', '=', 'pt.id']
      )
      ->addJoin(
        'PaymentProcessor AS pp',
        'INNER',
        NULL,
        ['pt.payment_processor_id', '=', 'pp.id']
      )
      ->addJoin(
        'PaymentProcessorType AS ppt',
        'INNER',
        NULL,
        ['pp.payment_processor_type_id', '=', 'ppt.id']
      )
      ->addWhere('contribution_status_id:name', 'IN', ['Pending', 'Partially paid'])
      ->addWhere('receive_date', '<=', 'now')
      ->addWhere('cr.contribution_status_id:name', '!=', 'Cancelled')
      ->addWhere('cr.payment_token_id', 'IS NOT NULL')
      // Handle NULL failure_count (treat as 0) - OR condition for NULL-safe comparison.
      ->addClause('OR', ['cr.failure_count', '<=', $maxRetryCount], ['cr.failure_count', 'IS NULL'])
      ->addWhere('ppt.name', '=', $processorType)
      ->addOrderBy('receive_date', 'ASC')
      ->setLimit($batchSize);

    // Exclude contributions with blocking PaymentAttempts.
    if (!empty($blockingAttemptContribIds)) {
      $query->addWhere('id', 'NOT IN', $blockingAttemptContribIds);
    }

    $contributions = $query->execute();

    $results = [];
    foreach ($contributions as $contrib) {
      if (!is_array($contrib)) {
        continue;
      }

      $id = intval($contrib['id'] ?? 0);
      $contactId = intval($contrib['contact_id'] ?? 0);
      $totalAmount = floatval($contrib['total_amount'] ?? 0);
      $paidAmount = floatval($contrib['paid_amount'] ?? 0);
      $currency = strval($contrib['currency'] ?? '');
      $recurId = intval($contrib['contribution_recur_id'] ?? 0);
      $tokenId = intval($contrib['cr.payment_token_id'] ?? 0);
      $processorId = intval($contrib['pt.payment_processor_id'] ?? 0);

      // Only include if there's an outstanding balance.
      if ($totalAmount - $paidAmount <= 0) {
        continue;
      }

      $results[] = [
        'id' => $id,
        'contact_id' => $contactId,
        'total_amount' => $totalAmount,
        'currency' => $currency,
        'contribution_recur_id' => $recurId,
        'paid_amount' => $paidAmount,
        'payment_token_id' => $tokenId,
        'payment_processor_id' => $processorId,
      ];
    }

    return $results;
  }

  /**
   * Get contribution IDs that have blocking PaymentAttempts.
   *
   * A blocking attempt is one with status: processing, completed, or cancelled.
   *
   * @return array<int>
   *   Array of contribution IDs.
   */
  private function getContributionIdsWithBlockingAttempts(): array {
    $attempts = \Civi\Api4\PaymentAttempt::get(FALSE)
      ->addSelect('contribution_id')
      ->addWhere('status', 'IN', ['processing', 'completed', 'cancelled'])
      ->execute();

    $ids = [];
    foreach ($attempts as $attempt) {
      if (!is_array($attempt)) {
        continue;
      }
      if (isset($attempt['contribution_id'])) {
        $ids[] = intval($attempt['contribution_id']);
      }
    }

    return $ids;
  }

  /**
   * Batch fetch existing PaymentAttempts for contributions.
   *
   * @param array<int> $contributionIds
   *   Array of contribution IDs.
   *
   * @return array<int, array<string, mixed>>
   *   PaymentAttempts indexed by contribution_id.
   */
  private function batchFetchPaymentAttempts(array $contributionIds): array {
    if (empty($contributionIds)) {
      return [];
    }

    $attempts = \Civi\Api4\PaymentAttempt::get(FALSE)
      ->addWhere('contribution_id', 'IN', $contributionIds)
      ->execute();

    $indexed = [];
    foreach ($attempts as $attempt) {
      if (!is_array($attempt)) {
        continue;
      }
      if (isset($attempt['contribution_id'])) {
        $indexed[intval($attempt['contribution_id'])] = $attempt;
      }
    }

    return $indexed;
  }

  /**
   * Get or create PaymentAttempt for a contribution.
   *
   * Returns the PaymentAttempt ID if it's in 'pending' status (new or existing).
   * Returns NULL if the attempt exists with a non-pending status.
   *
   * @param array<string, mixed> $contribution
   *   Contribution record.
   * @param array<string, mixed>|null $existingAttempt
   *   Existing PaymentAttempt or NULL.
   * @param string $processorType
   *   Payment processor type name.
   *
   * @return int|null
   *   PaymentAttempt ID or NULL if not eligible.
   */
  private function getOrCreatePaymentAttempt(array $contribution, ?array $existingAttempt, string $processorType): ?int {
    if ($existingAttempt !== NULL) {
      // Check if existing attempt is in pending status.
      if ($existingAttempt['status'] !== 'pending') {
        $this->logDebug('InstalmentChargeService: Skipping non-pending attempt', [
          'contributionId' => $contribution['id'],
          'status' => $existingAttempt['status'],
        ]);
        return NULL;
      }
      $attemptId = $existingAttempt['id'] ?? 0;
      return is_int($attemptId) ? $attemptId : (is_string($attemptId) ? intval($attemptId) : 0);
    }

    // Create new PaymentAttempt with pending status.
    $attempt = PaymentAttemptBAO::create([
      'contribution_id' => $contribution['id'],
      'contact_id' => $contribution['contact_id'],
      'payment_processor_id' => $contribution['payment_processor_id'],
      'processor_type' => strtolower($processorType),
      'status' => 'pending',
    ]);

    if (is_null($attempt)) {
      return NULL;
    }

    return intval($attempt->id);
  }

}
