<?php

namespace Civi\Api4\Action\ContributionPayability;

use Civi\Api4\Contribution;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Paymentprocessingcore\Payability\PayabilityResult;
use Civi\Paymentprocessingcore\Service\PayabilityProviderRegistry;

/**
 * Get the payability status of contributions for a contact.
 *
 * This action queries contributions and uses registered payability providers
 * to determine if each contribution can be paid now or is managed by the
 * payment processor (e.g., recurring subscriptions, payment plans).
 *
 * @method int getContactId()
 * @method $this setContactId(int $contactId)
 * @method array|null getContributionStatus()
 * @method $this setContributionStatus(array $status)
 * @method string|null getStartDate()
 * @method $this setStartDate(string $date)
 * @method string|null getEndDate()
 * @method $this setEndDate(string $date)
 */
class GetStatus extends AbstractAction {

  /**
   * Contact ID to check contributions for.
   *
   * @var int
   * @required
   */
  protected $contactId;

  /**
   * Filter by contribution status names.
   *
   * If not provided, all statuses are included.
   * Example: ['Pending', 'Partially paid']
   *
   * @var array|null
   */
  protected $contributionStatus;

  /**
   * Filter contributions received on or after this date.
   *
   * Format: YYYY-MM-DD
   *
   * @var string|null
   */
  protected $startDate;

  /**
   * Filter contributions received on or before this date.
   *
   * Format: YYYY-MM-DD
   *
   * @var string|null
   */
  protected $endDate;

  /**
   * Execute the action.
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result) {
    $contributions = $this->loadContributions();

    if (empty($contributions)) {
      return;
    }

    // Group contributions by payment processor type
    $groupedByProcessor = $this->groupByProcessorType($contributions);

    // Get payability registry
    $registry = $this->getPayabilityRegistry();

    // Process each processor type
    $payabilityResults = [];
    foreach ($groupedByProcessor as $processorType => $contributionIds) {
      if ($registry->hasProvider($processorType)) {
        $provider = $registry->getProvider($processorType);
        $providerResults = $provider->getPayabilityForContributions($contributionIds);
        $payabilityResults = array_merge($payabilityResults, $providerResults);
      }
    }

    // Build final result set
    foreach ($contributions as $contribution) {
      $contributionId = (int) $contribution['id'];
      $processorType = $contribution['payment_processor_type'] ?? NULL;

      // Base contribution data
      $row = [
        'id' => $contributionId,
        'contact_id' => (int) $contribution['contact_id'],
        'total_amount' => $contribution['total_amount'],
        'currency' => $contribution['currency'],
        'receive_date' => $contribution['receive_date'],
        'contribution_status' => $contribution['contribution_status_id:name'],
        'payment_processor_type' => $processorType,
      ];

      // Add payability info
      if (isset($payabilityResults[$contributionId])) {
        $payability = $payabilityResults[$contributionId];
        if ($payability instanceof PayabilityResult) {
          $row = array_merge($row, $payability->toArray());
        }
        else {
          // Handle array format from duck-typed providers
          $row['can_pay_now'] = $payability['can_pay_now'] ?? NULL;
          $row['payability_reason'] = $payability['payability_reason'] ?? NULL;
          $row['payment_type'] = $payability['payment_type'] ?? NULL;
          $row['payability_metadata'] = $payability['payability_metadata'] ?? [];
        }
      }
      else {
        // No provider registered for this processor type
        $row['can_pay_now'] = NULL;
        $row['payability_reason'] = $processorType
          ? "No payability provider registered for processor type: {$processorType}"
          : 'No payment processor associated';
        $row['payment_type'] = NULL;
        $row['payability_metadata'] = [];
      }

      $result[] = $row;
    }
  }

  /**
   * Load contributions for the contact with filters applied.
   *
   * @return array
   *
   * @throws \CRM_Core_Exception
   */
  private function loadContributions(): array {
    $query = Contribution::get($this->checkPermissions)
      ->addSelect(
        'id',
        'contact_id',
        'total_amount',
        'currency',
        'receive_date',
        'contribution_status_id:name',
        'contribution_recur_id',
        'payment_processor_id',
        'payment_processor_id.payment_processor_type_id:name'
      )
      ->addWhere('contact_id', '=', $this->contactId);

    // Apply status filter
    if (!empty($this->contributionStatus)) {
      $query->addWhere('contribution_status_id:name', 'IN', $this->contributionStatus);
    }

    // Apply date filters
    if (!empty($this->startDate)) {
      $query->addWhere('receive_date', '>=', $this->startDate);
    }
    if (!empty($this->endDate)) {
      $query->addWhere('receive_date', '<=', $this->endDate . ' 23:59:59');
    }

    $contributions = $query->execute()->getArrayCopy();

    // Normalize processor type field name
    foreach ($contributions as &$contribution) {
      $contribution['payment_processor_type'] =
        $contribution['payment_processor_id.payment_processor_type_id:name'] ?? NULL;
      unset($contribution['payment_processor_id.payment_processor_type_id:name']);
    }

    return $contributions;
  }

  /**
   * Group contribution IDs by payment processor type.
   *
   * @param array $contributions
   *
   * @return array<string, array<int>>
   *   Array keyed by processor type, containing arrays of contribution IDs.
   */
  private function groupByProcessorType(array $contributions): array {
    $grouped = [];

    foreach ($contributions as $contribution) {
      $processorType = $contribution['payment_processor_type'] ?? '_none_';
      $grouped[$processorType][] = (int) $contribution['id'];
    }

    return $grouped;
  }

  /**
   * Get the payability provider registry.
   *
   * @return \Civi\Paymentprocessingcore\Service\PayabilityProviderRegistry
   */
  private function getPayabilityRegistry(): PayabilityProviderRegistry {
    return \Civi::service('paymentprocessingcore.payability_registry');
  }

}
