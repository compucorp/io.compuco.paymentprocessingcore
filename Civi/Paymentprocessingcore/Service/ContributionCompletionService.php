<?php

namespace Civi\Paymentprocessingcore\Service;

use Civi\Api4\Contribution;
use Civi\Api4\ContributionPage;
use Civi\Paymentprocessingcore\Exception\ContributionCompletionException;

/**
 * Service for completing Pending contributions with payment processor transaction details.
 *
 * Generic service shared across all payment processors (Stripe, GoCardless, etc.).
 * Ensures idempotent completion (safe to call multiple times).
 *
 * @package Civi\Paymentprocessingcore\Service
 */
class ContributionCompletionService {

  /**
   * Complete a Pending contribution with payment processor transaction details.
   *
   * This method is idempotent - calling it multiple times with the same
   * contribution will not create duplicate accounting entries.
   *
   * @param int $contributionId CiviCRM contribution ID
   * @param string $transactionId Payment processor transaction ID (e.g., Stripe charge ID ch_..., GoCardless payment ID pm_...)
   * @param float|null $feeAmount Optional fee amount charged by payment processor
   * @param bool|null $sendReceipt Whether to send email receipt. If NULL, will check contribution page settings. Default: NULL
   *
   * @return array<string, mixed> Completion result with keys: 'success' => TRUE, 'contribution_id' => int, 'already_completed' => bool
   *
   * @phpstan-return array{success: true, contribution_id: int, already_completed: bool}
   *
   * @throws \Civi\Paymentprocessingcore\Exception\ContributionCompletionException If completion fails
   */
  public function complete(int $contributionId, string $transactionId, ?float $feeAmount = NULL, ?bool $sendReceipt = NULL): array {
    $contribution = $this->getContribution($contributionId);

    // Check if already completed (idempotency)
    if ($this->isAlreadyCompleted($contribution, $transactionId)) {
      return [
        'success' => TRUE,
        'contribution_id' => $contributionId,
        'already_completed' => TRUE,
      ];
    }

    // Validate contribution status
    if (!$this->isPending($contribution)) {
      throw new ContributionCompletionException(
        "Cannot complete contribution {$contributionId}: status is '{$contribution['contribution_status_id:name']}', expected 'Pending'",
        ['contribution_id' => $contributionId, 'status' => $contribution['contribution_status_id:name']]
      );
    }

    // Determine receipt setting
    if ($sendReceipt === NULL) {
      $sendReceipt = $this->shouldSendReceipt($contribution);
    }

    // Complete the transaction
    $this->completeTransaction($contribution, $transactionId, $feeAmount, $sendReceipt);

    return [
      'success' => TRUE,
      'contribution_id' => $contributionId,
      'already_completed' => FALSE,
    ];
  }

  /**
   * Get contribution by ID.
   *
   * @param int $contributionId Contribution ID
   *
   * @return array Contribution data
   *
   * @throws \Civi\Paymentprocessingcore\Exception\ContributionCompletionException If contribution not found
   */
  private function getContribution(int $contributionId): array {
    try {
      $contribution = Contribution::get(FALSE)
        ->addSelect('id', 'contribution_status_id:name', 'total_amount', 'currency', 'contribution_page_id', 'trxn_id')
        ->addWhere('id', '=', $contributionId)
        ->execute()
        ->first();

      if (!$contribution) {
        throw new ContributionCompletionException(
          "Contribution not found: {$contributionId}",
          ['contribution_id' => $contributionId]
        );
      }

      return $contribution;
    }
    catch (\Exception $e) {
      if ($e instanceof ContributionCompletionException) {
        throw $e;
      }
      throw new ContributionCompletionException(
        "Failed to load contribution {$contributionId}: " . $e->getMessage(),
        ['contribution_id' => $contributionId, 'error' => $e->getMessage()],
        $e
      );
    }
  }

  /**
   * Check if contribution is already completed (idempotency).
   *
   * @param array $contribution Contribution data
   * @param string $transactionId Transaction ID
   *
   * @return bool TRUE if already completed
   */
  private function isAlreadyCompleted(array $contribution, string $transactionId): bool {
    if ($contribution['contribution_status_id:name'] === 'Completed') {
      \Civi::log()->info('ContributionCompletionService: Contribution already completed - idempotency check', [
        'contribution_id' => $contribution['id'],
        'transaction_id' => $transactionId,
        'existing_trxn_id' => $contribution['trxn_id'] ?? NULL,
      ]);
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Check if contribution is Pending (can be completed).
   *
   * @param array $contribution Contribution data
   *
   * @return bool TRUE if Pending
   */
  private function isPending(array $contribution): bool {
    return $contribution['contribution_status_id:name'] === 'Pending';
  }

  /**
   * Determine if receipt should be sent based on contribution page settings.
   *
   * @param array $contribution Contribution data
   *
   * @return bool TRUE if receipt should be sent
   */
  private function shouldSendReceipt(array $contribution): bool {
    if (empty($contribution['contribution_page_id'])) {
      // No contribution page (e.g., backend contribution) - default to no receipt
      return FALSE;
    }

    try {
      $contributionPage = ContributionPage::get(FALSE)
        ->addSelect('is_email_receipt')
        ->addWhere('id', '=', $contribution['contribution_page_id'])
        ->execute()
        ->first();

      return !empty($contributionPage['is_email_receipt']);
    }
    catch (\Exception $e) {
      \Civi::log()->warning('ContributionCompletionService: Failed to load contribution page settings', [
        'contribution_id' => $contribution['id'],
        'contribution_page_id' => $contribution['contribution_page_id'],
        'error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Complete the contribution transaction.
   *
   * Calls Contribution.completetransaction API which automatically:
   * - Creates payment record
   * - Posts accounting entries (A/R + Payment)
   * - Updates contribution status to Completed
   * - Sends receipt email if requested
   *
   * @param array $contribution Contribution data
   * @param string $transactionId Payment processor transaction ID
   * @param float|null $feeAmount Optional fee amount
   * @param bool $sendReceipt Whether to send email receipt
   *
   * @return void
   *
   * @throws \Civi\Paymentprocessingcore\Exception\ContributionCompletionException If completion fails
   */
  private function completeTransaction(array $contribution, string $transactionId, ?float $feeAmount, bool $sendReceipt): void {
    try {
      $params = [
        'id' => $contribution['id'],
        'trxn_id' => $transactionId,
        'is_email_receipt' => $sendReceipt ? 1 : 0,
      ];

      // Add fee amount if provided
      if ($feeAmount !== NULL) {
        $params['fee_amount'] = $feeAmount;
      }

      civicrm_api3('Contribution', 'completetransaction', $params);

      \Civi::log()->info('ContributionCompletionService: Contribution completed successfully', [
        'contribution_id' => $contribution['id'],
        'transaction_id' => $transactionId,
        'fee_amount' => $feeAmount,
        'amount' => $contribution['total_amount'],
        'currency' => $contribution['currency'],
        'receipt_sent' => $sendReceipt,
      ]);
    }
    catch (\CiviCRM_API3_Exception $e) {
      throw new ContributionCompletionException(
        "Failed to complete contribution {$contribution['id']}: " . $e->getMessage(),
        [
          'contribution_id' => $contribution['id'],
          'transaction_id' => $transactionId,
          'error' => $e->getMessage(),
          'error_data' => $e->getExtraParams(),
        ],
        $e
      );
    }
  }

}
