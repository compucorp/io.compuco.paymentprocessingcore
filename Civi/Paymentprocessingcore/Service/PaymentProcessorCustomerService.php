<?php

namespace Civi\Paymentprocessingcore\Service;

use Civi\Api4\PaymentProcessorCustomer;
use Civi\Paymentprocessingcore\Exception\PaymentProcessorCustomerException;

/**
 * Service for managing payment processor customer IDs.
 *
 * Generic service shared across all payment processors (Stripe, GoCardless, etc.).
 *
 * @package Civi\Paymentprocessingcore\Service
 */
class PaymentProcessorCustomerService {

  /**
   * Get customer ID for a contact on a payment processor.
   *
   * @param int $contactId CiviCRM contact ID
   * @param int $paymentProcessorId Payment processor ID
   *
   * @return string|null Processor customer ID (e.g., cus_...) or NULL if not found
   */
  public function getCustomerId(int $contactId, int $paymentProcessorId): ?string {
    try {
      $customer = PaymentProcessorCustomer::get(FALSE)
        ->addSelect('processor_customer_id')
        ->addWhere('contact_id', '=', $contactId)
        ->addWhere('payment_processor_id', '=', $paymentProcessorId)
        ->execute()
        ->first();

      if ($customer) {
        \Civi::log()->debug('PaymentProcessorCustomerService: Found existing customer', [
          'contact_id' => $contactId,
          'payment_processor_id' => $paymentProcessorId,
          'processor_customer_id' => $customer['processor_customer_id'],
        ]);
        return $customer['processor_customer_id'];
      }

      return NULL;
    }
    catch (\Exception $e) {
      \Civi::log()->error('PaymentProcessorCustomerService: Failed to lookup customer', [
        'contact_id' => $contactId,
        'payment_processor_id' => $paymentProcessorId,
        'error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Get existing customer ID or create new one using callback.
   *
   * This is the recommended method for payment processors.
   *
   * @param int $contactId CiviCRM contact ID
   * @param int $paymentProcessorId Payment processor ID
   * @param callable $createCallback Callback to create customer on processor. Should return processor customer ID string.
   *
   * @return string Processor customer ID (existing or newly created)
   *
   * @throws \Civi\Paymentprocessingcore\Exception\PaymentProcessorCustomerException If customer creation fails
   */
  public function getOrCreateCustomerId(int $contactId, int $paymentProcessorId, callable $createCallback): string {
    // Try to get existing customer
    $customerId = $this->getCustomerId($contactId, $paymentProcessorId);

    if ($customerId) {
      return $customerId;
    }

    // Create new customer on processor
    try {
      $processorCustomerId = $createCallback();

      if (empty($processorCustomerId) || !is_string($processorCustomerId)) {
        throw new PaymentProcessorCustomerException(
          'Create callback must return a non-empty string customer ID',
          ['contact_id' => $contactId, 'payment_processor_id' => $paymentProcessorId]
        );
      }

      // Store customer ID
      $this->storeCustomerId($contactId, $paymentProcessorId, $processorCustomerId);

      \Civi::log()->info('PaymentProcessorCustomerService: Created and stored new customer', [
        'contact_id' => $contactId,
        'payment_processor_id' => $paymentProcessorId,
        'processor_customer_id' => $processorCustomerId,
      ]);

      return $processorCustomerId;
    }
    catch (PaymentProcessorCustomerException $e) {
      throw $e;
    }
    catch (\Exception $e) {
      throw new PaymentProcessorCustomerException(
        "Failed to create customer for contact {$contactId}: " . $e->getMessage(),
        ['contact_id' => $contactId, 'payment_processor_id' => $paymentProcessorId, 'error' => $e->getMessage()],
        $e
      );
    }
  }

  /**
   * Store customer ID for a contact on a payment processor.
   *
   * Creates or updates the customer record.
   *
   * @param int $contactId CiviCRM contact ID
   * @param int $paymentProcessorId Payment processor ID
   * @param string $processorCustomerId Processor customer ID (e.g., cus_...)
   *
   * @return void
   *
   * @throws \Civi\Paymentprocessingcore\Exception\PaymentProcessorCustomerException If storage fails
   */
  public function storeCustomerId(int $contactId, int $paymentProcessorId, string $processorCustomerId): void {
    try {
      // Check if record exists
      $existing = PaymentProcessorCustomer::get(FALSE)
        ->addWhere('contact_id', '=', $contactId)
        ->addWhere('payment_processor_id', '=', $paymentProcessorId)
        ->execute()
        ->first();

      if ($existing) {
        // Update existing record
        PaymentProcessorCustomer::update(FALSE)
          ->addWhere('id', '=', $existing['id'])
          ->addValue('processor_customer_id', $processorCustomerId)
          ->execute();

        \Civi::log()->info('PaymentProcessorCustomerService: Updated existing customer record', [
          'contact_id' => $contactId,
          'payment_processor_id' => $paymentProcessorId,
          'processor_customer_id' => $processorCustomerId,
        ]);
      }
      else {
        // Create new record
        PaymentProcessorCustomer::create(FALSE)
          ->addValue('contact_id', $contactId)
          ->addValue('payment_processor_id', $paymentProcessorId)
          ->addValue('processor_customer_id', $processorCustomerId)
          ->execute();

        \Civi::log()->info('PaymentProcessorCustomerService: Created new customer record', [
          'contact_id' => $contactId,
          'payment_processor_id' => $paymentProcessorId,
          'processor_customer_id' => $processorCustomerId,
        ]);
      }
    }
    catch (\Exception $e) {
      throw new PaymentProcessorCustomerException(
        "Failed to store customer ID for contact {$contactId}: " . $e->getMessage(),
        [
          'contact_id' => $contactId,
          'payment_processor_id' => $paymentProcessorId,
          'processor_customer_id' => $processorCustomerId,
          'error' => $e->getMessage(),
        ],
        $e
      );
    }
  }

  /**
   * Delete customer record for a contact on a payment processor.
   *
   * Note: This only removes the CiviCRM record, not the customer on the processor.
   *
   * @param int $contactId CiviCRM contact ID
   * @param int $paymentProcessorId Payment processor ID
   *
   * @return bool TRUE if deleted, FALSE if not found
   */
  public function deleteCustomerId(int $contactId, int $paymentProcessorId): bool {
    try {
      $result = PaymentProcessorCustomer::delete(FALSE)
        ->addWhere('contact_id', '=', $contactId)
        ->addWhere('payment_processor_id', '=', $paymentProcessorId)
        ->execute();

      if (count($result) > 0) {
        \Civi::log()->info('PaymentProcessorCustomerService: Deleted customer record', [
          'contact_id' => $contactId,
          'payment_processor_id' => $paymentProcessorId,
        ]);
        return TRUE;
      }

      return FALSE;
    }
    catch (\Exception $e) {
      \Civi::log()->error('PaymentProcessorCustomerService: Failed to delete customer record', [
        'contact_id' => $contactId,
        'payment_processor_id' => $paymentProcessorId,
        'error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

}
