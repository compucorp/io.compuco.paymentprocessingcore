<?php

namespace Civi\Api4;

/**
 * ContributionPayability API - Check if contributions can be paid now.
 *
 * This API provides a generic way to check the payability status of
 * contributions across multiple payment processors. Each processor
 * extension registers a PayabilityProvider that implements
 * processor-specific logic for determining if a contribution can be
 * paid immediately or is managed by the payment processor.
 *
 * @searchable none
 * @since 1.0
 * @package Civi\Api4
 */
class ContributionPayability extends Generic\AbstractEntity {

  /**
   * Get the payability status of contributions for a contact.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Action\ContributionPayability\GetStatus
   */
  public static function getStatus($checkPermissions = TRUE) {
    return (new Action\ContributionPayability\GetStatus(__CLASS__, __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * Get field definitions for the entity.
   *
   * @param bool $checkPermissions
   *
   * @return \Civi\Api4\Generic\BasicGetFieldsAction
   */
  public static function getFields($checkPermissions = TRUE) {
    return (new Generic\BasicGetFieldsAction(__CLASS__, __FUNCTION__, function () {
      return [
        [
          'name' => 'id',
          'title' => 'Contribution ID',
          'data_type' => 'Integer',
          'readonly' => TRUE,
        ],
        [
          'name' => 'contact_id',
          'title' => 'Contact ID',
          'data_type' => 'Integer',
          'readonly' => TRUE,
        ],
        [
          'name' => 'total_amount',
          'title' => 'Total Amount',
          'data_type' => 'Money',
          'readonly' => TRUE,
        ],
        [
          'name' => 'currency',
          'title' => 'Currency',
          'data_type' => 'String',
          'readonly' => TRUE,
        ],
        [
          'name' => 'receive_date',
          'title' => 'Receive Date',
          'data_type' => 'Timestamp',
          'readonly' => TRUE,
        ],
        [
          'name' => 'contribution_status',
          'title' => 'Contribution Status',
          'data_type' => 'String',
          'readonly' => TRUE,
        ],
        [
          'name' => 'payment_processor_type',
          'title' => 'Payment Processor Type',
          'data_type' => 'String',
          'readonly' => TRUE,
        ],
        [
          'name' => 'can_pay_now',
          'title' => 'Can Pay Now',
          'data_type' => 'Boolean',
          'readonly' => TRUE,
          'description' => 'TRUE if user can pay, FALSE if managed by processor, NULL if no provider registered',
        ],
        [
          'name' => 'payability_reason',
          'title' => 'Payability Reason',
          'data_type' => 'String',
          'readonly' => TRUE,
        ],
        [
          'name' => 'payment_type',
          'title' => 'Payment Type',
          'data_type' => 'String',
          'readonly' => TRUE,
          'description' => 'Type: one_off, subscription, or payment_plan',
        ],
        [
          'name' => 'payability_metadata',
          'title' => 'Payability Metadata',
          'data_type' => 'Array',
          'readonly' => TRUE,
          'description' => 'Processor-specific metadata',
        ],
      ];
    }))->setCheckPermissions($checkPermissions);
  }

  /**
   * Define permissions for the entity.
   *
   * @return array
   */
  public static function permissions() {
    return [
      'meta' => ['access CiviCRM'],
      'default' => ['access CiviContribute'],
      'getStatus' => ['access CiviContribute'],
    ];
  }

}
