<?php
use CRM_Paymentprocessingcore_ExtensionUtil as E;

/**
 * Business Access Object for PaymentProcessorCustomer entity.
 *
 * Stores payment processor customer IDs for all processors (Stripe, GoCardless, etc.).
 */
class CRM_Paymentprocessingcore_BAO_PaymentProcessorCustomer extends CRM_Paymentprocessingcore_DAO_PaymentProcessorCustomer {

  /**
   * Create a new PaymentProcessorCustomer based on array-data.
   *
   * @param array $params key-value pairs
   * @return CRM_Paymentprocessingcore_DAO_PaymentProcessorCustomer|NULL
   */
  public static function create(array $params): ?CRM_Paymentprocessingcore_DAO_PaymentProcessorCustomer {
    $className = 'CRM_Paymentprocessingcore_DAO_PaymentProcessorCustomer';
    $entityName = 'PaymentProcessorCustomer';
    $hook = empty($params['id']) ? 'create' : 'edit';

    $id = NULL;
    if (!empty($params['id']) && is_numeric($params['id'])) {
      $id = (int) $params['id'];
    }
    CRM_Utils_Hook::pre($hook, $entityName, $id, $params);
    $instance = new $className();
    $instance->copyValues($params);
    $instance->save();
    CRM_Utils_Hook::post($hook, $entityName, (int) $instance->id, $instance);

    return $instance;
  }

  /**
   * Find customer by contact ID and payment processor ID.
   *
   * @param int $contactId Contact ID
   * @param int $paymentProcessorId Payment Processor ID
   *
   * @return array|null Customer data or NULL if not found
   *
   * @phpstan-return array{id: int, contact_id: int, payment_processor_id: int, processor_customer_id: string, created_date: string}|null
   */
  public static function findByContactAndProcessor(int $contactId, int $paymentProcessorId): ?array {
    if (empty($contactId) || empty($paymentProcessorId)) {
      return NULL;
    }

    $customer = new self();
    $customer->contact_id = $contactId;
    $customer->payment_processor_id = $paymentProcessorId;

    if ($customer->find(TRUE)) {
      /** @var array{id: int, contact_id: int, payment_processor_id: int, processor_customer_id: string, created_date: string} $data */
      $data = $customer->toArray();
      return $data;
    }

    return NULL;
  }

  /**
   * Find customer by processor customer ID and payment processor ID.
   *
   * @param string $processorCustomerId Processor customer ID
   * @param int $paymentProcessorId Payment Processor ID
   *
   * @return array|null Customer data or NULL if not found
   *
   * @phpstan-return array{id: int, contact_id: int, payment_processor_id: int, processor_customer_id: string, created_date: string}|null
   */
  public static function findByProcessorCustomerId(string $processorCustomerId, int $paymentProcessorId): ?array {
    if (empty($processorCustomerId) || empty($paymentProcessorId)) {
      return NULL;
    }

    $customer = new self();
    $customer->processor_customer_id = $processorCustomerId;
    $customer->payment_processor_id = $paymentProcessorId;

    if ($customer->find(TRUE)) {
      /** @var array{id: int, contact_id: int, payment_processor_id: int, processor_customer_id: string, created_date: string} $data */
      $data = $customer->toArray();
      return $data;
    }

    return NULL;
  }

}
