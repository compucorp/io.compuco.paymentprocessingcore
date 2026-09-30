<?php
// phpcs:disable
use CRM_Paymentprocessingcore_ExtensionUtil as E;
// phpcs:enable

/**
 * Collection of upgrade steps.
 */
class CRM_Paymentprocessingcore_Upgrader extends CRM_Extension_Upgrader_Base {

  /**
   * Backfill the payment processor on payments this extension completed without one.
   *
   * Until now ContributionCompletionService did not pass the payment processor to
   * Contribution.completetransaction, so core left payment_processor_id empty on the financial
   * transaction it created, and Finance Extras would not offer a refund for those payments.
   *
   * @return bool
   */
  public function upgrade_1001(): bool {
    \Civi::log()->info('Payment Processing Core upgrade 1001: backfilling the payment processor on payment transactions');

    $backfilled = (new CRM_Paymentprocessingcore_Upgrader_PaymentProcessorBackfill())->run();

    \Civi::log()->info("Payment Processing Core upgrade 1001: payment transactions given a payment processor: {$backfilled}");

    return TRUE;
  }

}
