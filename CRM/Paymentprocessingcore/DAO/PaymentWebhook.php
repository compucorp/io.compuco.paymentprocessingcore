<?php

/**
 * DAOs provide an OOP-style facade for reading and writing database records.
 *
 * DAOs are a primary source for metadata in older versions of CiviCRM (<5.74)
 * and are required for some subsystems (such as APIv3).
 *
 * This stub provides compatibility. It is not intended to be modified in a
 * substantive way. Property annotations may be added, but are not required.
 * @property string $id
 * @property string $event_id
 * @property string $processor_type
 * @property string $event_type
 * @property string $payment_attempt_id
 * @property string $status
 * @property string $attempts
 * @property string $next_retry_at
 * @property string $result
 * @property string $error_log
 * @property string $processing_started_at
 * @property string $processed_at
 * @property string $created_date
 */
class CRM_Paymentprocessingcore_DAO_PaymentWebhook extends CRM_Paymentprocessingcore_DAO_Base {

  /**
   * Required by older versions of CiviCRM (<5.74).
   * @var string
   */
  public static $_tableName = 'civicrm_payment_webhook';

}
