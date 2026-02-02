<?php

namespace Civi\Paymentprocessingcore\Service;

use Civi\Paymentprocessingcore\DTO\RecurringContributionDTO;
use Civi\Paymentprocessingcore\Helper\DebugLoggerTrait;

/**
 * Service to generate instalment contributions for due recurring contributions.
 *
 * Creates Pending contribution records for each In Progress recurring
 * contribution whose next_sched_contribution_date is due, excluding
 * membership-linked recurrences. No payment processor calls are made.
 */
class InstalmentGenerationService {

  use DebugLoggerTrait;

  /**
   * Default batch size for processing.
   */
  public const DEFAULT_BATCH_SIZE = 500;

  /**
   * Default payment processor type.
   */
  public const DEFAULT_PROCESSOR_TYPE = 'Stripe';

  /**
   * Generate instalments for all due recurring contributions.
   *
   * @param string $processorType
   *   Payment processor type name (e.g. "Stripe").
   * @param int $batchSize
   *   Maximum number of records to process.
   * @param string|null $referenceDate
   *   Optional reference date (Y-m-d) for determining due contributions.
   *   Defaults to today.
   *
   * @return array{created: int, skipped: int, errored: int, errors: array<int, string>, message: string}
   *   Summary of processing results.
   */
  public function generateInstalments(string $processorType, int $batchSize, ?string $referenceDate = NULL): array {
    $referenceDate = $referenceDate ?? date('Y-m-d');

    $this->logDebug('InstalmentGenerationService::generateInstalments: Starting', [
      'processorType' => $processorType,
      'batchSize' => $batchSize,
      'referenceDate' => $referenceDate,
    ]);

    $summary = [
      'created' => 0,
      'skipped' => 0,
      'errored' => 0,
      'errors' => [],
    ];

    $dueRecurrings = $this->getDueRecurringContributions($processorType, $batchSize, $referenceDate);

    $this->logDebug('InstalmentGenerationService::generateInstalments: Found due recurrings', [
      'count' => count($dueRecurrings),
    ]);

    foreach ($dueRecurrings as $recur) {
      try {
        $recurId = $recur->getId();
        $receiveDate = $recur->getNextSchedContributionDate();

        if ($this->instalmentExists($recurId, $receiveDate)) {
          $this->logDebug('InstalmentGenerationService::generateInstalments: Skipping duplicate instalment', [
            'recurId' => $recurId,
            'receiveDate' => $receiveDate,
          ]);
          $summary['skipped']++;
          continue;
        }

        $tx = new \CRM_Core_Transaction();
        try {
          $this->createInstalment($recur, $receiveDate);
          $this->advanceScheduleDate($recurId, $receiveDate, $recur->getFrequencyUnit(), $recur->getFrequencyInterval());
          $tx->commit();
          $summary['created']++;
        }
        catch (\Throwable $e) {
          $tx->rollback();
          throw $e;
        }

        $this->logDebug('InstalmentGenerationService::generateInstalments: Created instalment', [
          'recurId' => $recurId,
          'receiveDate' => $receiveDate,
        ]);
      }
      catch (\Throwable $e) {
        $recurId = isset($recurId) ? $recurId : 0;
        $summary['errored']++;
        $summary['errors'][$recurId] = $e->getMessage();

        $this->logDebug('InstalmentGenerationService::generateInstalments: Error processing recur', [
          'recurId' => $recurId,
          'error' => $e->getMessage(),
        ]);
      }
    }

    $summary['message'] = sprintf(
      'Processed %d due recurring contributions: %d instalments created, %d skipped (contribution already exists for scheduled date), %d errors.',
      $summary['created'] + $summary['skipped'] + $summary['errored'],
      $summary['created'],
      $summary['skipped'],
      $summary['errored']
    );

    $this->logDebug('InstalmentGenerationService::generateInstalments: Completed', $summary);

    return $summary;
  }

  /**
   * Get recurring contributions that are due for instalment generation.
   *
   * @param string $processorType
   *   Payment processor type name.
   * @param int $batchSize
   *   Maximum number of records to return.
   * @param string|null $referenceDate
   *   Optional reference date (Y-m-d). Defaults to today.
   *
   * @return array<int, RecurringContributionDTO>
   *   Array of recurring contribution DTOs.
   */
  public function getDueRecurringContributions(string $processorType, int $batchSize, ?string $referenceDate = NULL): array {
    $dateOnly = $referenceDate ?? date('Y-m-d');
    $nextDayTimestamp = strtotime($dateOnly . ' +1 day');
    if ($nextDayTimestamp === FALSE) {
      throw new \InvalidArgumentException('Invalid reference date: ' . $dateOnly);
    }
    $nextDay = date('Y-m-d', $nextDayTimestamp);

    $result = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addSelect(
        'id',
        'next_sched_contribution_date',
        'frequency_unit:name',
        'frequency_interval',
        'contact_id',
        'amount',
        'currency',
        'financial_type_id',
        'campaign_id'
      )
      ->addJoin(
        'PaymentProcessor AS pp',
        'INNER',
        NULL,
        ['payment_processor_id', '=', 'pp.id']
      )
      ->addJoin(
        'PaymentProcessorType AS ppt',
        'INNER',
        NULL,
        ['pp.payment_processor_type_id', '=', 'ppt.id']
      )
      ->addJoin(
        'Membership AS m',
        'LEFT',
        NULL,
        ['id', '=', 'm.contribution_recur_id']
      )
      ->addWhere('contribution_status_id:name', '=', 'In Progress')
      ->addWhere('next_sched_contribution_date', '<', $nextDay . ' 00:00:00')
      ->addWhere('ppt.name', '=', $processorType)
      ->addWhere('m.id', 'IS NULL')
      ->setLimit($batchSize)
      ->execute()
      ->getArrayCopy();

    $dtos = [];
    foreach ($result as $record) {
      /** @var array<string, mixed> $record */
      $dtos[] = RecurringContributionDTO::fromApiResult($record);
    }

    return $dtos;
  }

  /**
   * Check if an instalment already exists for the given recur and date.
   *
   * @param int $recurId
   *   The recurring contribution ID.
   * @param string $receiveDate
   *   The receive date to check.
   *
   * @return bool
   *   TRUE if an instalment already exists.
   */
  public function instalmentExists(int $recurId, string $receiveDate): bool {
    $dateTimestamp = strtotime($receiveDate);
    if ($dateTimestamp === FALSE) {
      throw new \InvalidArgumentException('Invalid receive date: ' . $receiveDate);
    }
    $dateOnly = date('Y-m-d', $dateTimestamp);
    $nextDayTimestamp = strtotime($dateOnly . ' +1 day');
    if ($nextDayTimestamp === FALSE) {
      throw new \InvalidArgumentException('Invalid date calculation for: ' . $dateOnly);
    }
    $nextDay = date('Y-m-d', $nextDayTimestamp);
    $count = \Civi\Api4\Contribution::get(FALSE)
      ->selectRowCount()
      ->addWhere('contribution_recur_id', '=', $recurId)
      ->addWhere('receive_date', '>=', $dateOnly . ' 00:00:00')
      ->addWhere('receive_date', '<', $nextDay . ' 00:00:00')
      ->addWhere('contribution_status_id:name', 'IN', [
        'Pending',
        'Completed',
        'Failed',
        'Cancelled',
      ])
      ->execute()
      ->rowCount;

    return $count > 0;
  }

  /**
   * Create an instalment contribution from recurring contribution fields.
   *
   * Copies contact_id, financial_type_id, total_amount, currency, and
   * campaign_id from the recurring contribution record. Sets is_pay_later
   * to 0 and invoice_id to NULL.
   *
   * @param \Civi\Paymentprocessingcore\DTO\RecurringContributionDTO $recur
   *   The recurring contribution DTO.
   * @param string $receiveDate
   *   The receive date for the new contribution.
   *
   * @return int
   *   The new contribution ID.
   *
   * @throws \RuntimeException
   */
  public function createInstalment(RecurringContributionDTO $recur, string $receiveDate): int {
    $createAction = \Civi\Api4\Contribution::create(FALSE)
      ->addValue('contact_id', $recur->getContactId())
      ->addValue('financial_type_id', $recur->getFinancialTypeId())
      ->addValue('total_amount', $recur->getAmount())
      ->addValue('currency', $recur->getCurrency())
      ->addValue('contribution_recur_id', $recur->getId())
      ->addValue('receive_date', $receiveDate)
      ->addValue('contribution_status_id:name', 'Pending')
      ->addValue('is_pay_later', 0)
      ->addValue('invoice_id', NULL);

    if ($recur->getCampaignId() !== NULL) {
      $createAction->addValue('campaign_id', $recur->getCampaignId());
    }

    $result = $createAction->execute()->first();

    if (!is_array($result)) {
      throw new \RuntimeException(
        'Failed to create instalment for recurring contribution ' . $recur->getId()
      );
    }

    return (int) $result['id'];
  }

  /**
   * Advance the next scheduled contribution date.
   *
   * @param int $recurId
   *   The recurring contribution ID.
   * @param string $currentDate
   *   The current scheduled date.
   * @param string $frequencyUnit
   *   The frequency unit (day, week, month, year).
   * @param int $frequencyInterval
   *   The frequency interval.
   */
  public function advanceScheduleDate(
    int $recurId,
    string $currentDate,
    string $frequencyUnit,
    int $frequencyInterval
  ): void {
    $date = new \DateTime($currentDate);

    if ($frequencyUnit === 'month') {
      $originalDay = (int) $date->format('j');
      $date->modify('first day of +' . $frequencyInterval . ' month');
      $lastDayOfTargetMonth = (int) $date->format('t');
      $targetDay = min($originalDay, $lastDayOfTargetMonth);
      $date->setDate((int) $date->format('Y'), (int) $date->format('n'), $targetDay);
    }
    else {
      $date->modify('+' . $frequencyInterval . ' ' . $frequencyUnit);
    }

    \Civi\Api4\ContributionRecur::update(FALSE)
      ->addWhere('id', '=', $recurId)
      ->addValue('next_sched_contribution_date', $date->format('Y-m-d H:i:s'))
      ->execute();
  }

}
