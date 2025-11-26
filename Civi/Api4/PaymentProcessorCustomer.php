<?php
namespace Civi\Api4;

/**
 * PaymentProcessorCustomer entity - Stores payment processor customer IDs for all processors.
 *
 * @searchable secondary
 * @since 1.0
 * @package Civi\Api4
 */
class PaymentProcessorCustomer extends Generic\DAOEntity {

  /**
   * @return string
   */
  protected static function getDaoName(): string {
    return 'CRM_Paymentprocessingcore_DAO_PaymentProcessorCustomer';
  }

}
