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
 * @property string $contribution_id
 * @property string $contact_id
 * @property string $payment_processor_id
 * @property string $processor_type
 * @property string $processor_session_id
 * @property string $processor_payment_id
 * @property string $status
 * @property string $created_date
 * @property string $updated_date
 */
class CRM_Paymentprocessingcore_DAO_PaymentAttempt extends CRM_Paymentprocessingcore_DAO_Base {

  /**
   * Required by older versions of CiviCRM (<5.74).
   * @var string
   */
  public static $_tableName = 'civicrm_payment_attempt';

}
