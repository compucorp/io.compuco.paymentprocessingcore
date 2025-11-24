<?php
use CRM_Paymentprocessingcore_ExtensionUtil as E;

/**
 * Business Access Object for PaymentWebhook entity (generic across all processors)
 *
 * Webhook event log for de-duplication and idempotency across all processors.
 * Prevents duplicate webhook processing using unique event_id constraint.
 */
class CRM_Paymentprocessingcore_BAO_PaymentWebhook extends CRM_Paymentprocessingcore_DAO_PaymentWebhook {

  /**
   * Create a new PaymentWebhook based on array-data
   *
   * @param array $params key-value pairs
   * @return CRM_Paymentprocessingcore_DAO_PaymentWebhook|NULL
   */
  public static function create($params) {
    $className = 'CRM_Paymentprocessingcore_DAO_PaymentWebhook';
    $entityName = 'PaymentWebhook';
    $hook = empty($params['id']) ? 'create' : 'edit';

    CRM_Utils_Hook::pre($hook, $entityName, CRM_Utils_Array::value('id', $params), $params);
    $instance = new $className();
    $instance->copyValues($params);
    $instance->save();
    CRM_Utils_Hook::post($hook, $entityName, $instance->id, $instance);

    return $instance;
  }

  /**
   * Find a PaymentWebhook record by event ID
   *
   * @param string $eventId Processor event ID (evt_... for Stripe)
   * @return array|null Array of webhook data or NULL if not found
   */
  public static function findByEventId($eventId) {
    if (empty($eventId)) {
      return NULL;
    }

    $webhook = new self();
    $webhook->event_id = $eventId;

    if ($webhook->find(TRUE)) {
      return $webhook->toArray();
    }

    return NULL;
  }

  /**
   * Check if an event has already been processed (for idempotency)
   *
   * @param string $eventId Processor event ID
   * @return bool TRUE if event has been processed, FALSE otherwise
   */
  public static function isProcessed($eventId) {
    $webhook = self::findByEventId($eventId);
    return !empty($webhook) && in_array($webhook['status'], ['processed', 'processing']);
  }

  /**
   * Get available statuses for PaymentWebhook
   *
   * @return array Status options
   */
  public static function getStatuses() {
    return [
      'new' => E::ts('New'),
      'processing' => E::ts('Processing'),
      'processed' => E::ts('Processed'),
      'error' => E::ts('Error'),
    ];
  }

}
