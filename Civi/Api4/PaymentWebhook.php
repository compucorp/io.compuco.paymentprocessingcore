<?php
namespace Civi\Api4;

/**
 * PaymentWebhook entity - Webhook event log for de-duplication and idempotency.
 *
 * @searchable secondary
 * @since 1.0
 * @package Civi\Api4
 */
class PaymentWebhook extends Generic\DAOEntity {

  /**
   * @return string
   */
  protected static function getDaoName(): string {
    return 'CRM_Paymentprocessingcore_DAO_PaymentWebhook';
  }

}
