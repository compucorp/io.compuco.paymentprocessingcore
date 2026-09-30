<?php

use CRM_Paymentprocessingcore_ExtensionUtil as E;

/**
 * Repairs payment transactions that were recorded without the payment processor that took them.
 *
 * Contributions completed by this extension before the processor was passed to
 * Contribution.completetransaction were left with an empty payment_processor_id on their financial
 * transaction. Anything that reads the processor back off the transaction - Finance Extras refunds
 * in particular - therefore treats those payments as not refundable.
 *
 * The payment attempt recorded alongside each payment says which processor took it, so the historic
 * transactions can be repaired from it.
 */
class CRM_Paymentprocessingcore_Upgrader_PaymentProcessorBackfill {

  /**
   * Payment transactions joined to the payment attempt that says which processor took the payment.
   *
   * The join relies on contribution_id being unique on civicrm_payment_attempt, which is what makes
   * the attempt for a contribution unambiguous. Should that index ever be dropped, this needs a
   * deterministic way to choose between a contribution's attempts.
   */
  private const TABLES = '
    civicrm_financial_trxn ft
    INNER JOIN civicrm_entity_financial_trxn eft
      ON eft.financial_trxn_id = ft.id
      AND eft.entity_table = "civicrm_contribution"
    INNER JOIN civicrm_payment_attempt attempt
      ON attempt.contribution_id = eft.entity_id
      AND attempt.payment_processor_id IS NOT NULL
  ';

  /**
   * Restricts the backfill to payment transactions that are still missing their processor.
   */
  private const CONDITION = '
    WHERE ft.payment_processor_id IS NULL
      AND ft.is_payment = 1
  ';

  /**
   * Record the payment processor on every payment transaction that is missing it.
   *
   * Only transactions with no processor are touched, so this is safe to run more than once.
   *
   * @return int How many payment transactions were given a processor
   */
  public function run(): int {
    $missingBefore = $this->countTransactionsMissingProcessor();

    if ($missingBefore === 0) {
      return 0;
    }

    CRM_Core_DAO::executeQuery(
      'UPDATE ' . self::TABLES
      . ' SET ft.payment_processor_id = attempt.payment_processor_id '
      . self::CONDITION
    );

    return $missingBefore - $this->countTransactionsMissingProcessor();
  }

  /**
   * How many payment transactions could still take a processor from a payment attempt.
   *
   * @return int
   */
  public function countTransactionsMissingProcessor(): int {
    return (int) CRM_Core_DAO::singleValueQuery(
      'SELECT COUNT(*) FROM ' . self::TABLES . self::CONDITION
    );
  }

}
