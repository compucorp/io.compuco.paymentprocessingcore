<?php

namespace Civi\Paymentprocessingcore\Service;

use Civi\Api4\Contact;
use Civi\Api4\PaymentProcessor;
use Civi\Api4\PaymentProcessorCustomer;
use Civi\Paymentprocessingcore\Exception\PaymentProcessorCustomerException;

/**
 * Tests for PaymentProcessorCustomerService.
 *
 * @group headless
 */
class PaymentProcessorCustomerServiceTest extends \BaseHeadlessTest {

  /**
   * @var \Civi\Paymentprocessingcore\Service\PaymentProcessorCustomerService
   */
  private PaymentProcessorCustomerService $service;

  /**
   * @var int
   */
  private int $contactId;

  /**
   * @var int
   */
  private int $paymentProcessorId;

  /**
   * Set up test fixtures.
   */
  public function setUp(): void {
    parent::setUp();

    // Get service from container
    $service = \Civi::service('paymentprocessingcore.payment_processor_customer');
    if (!($service instanceof PaymentProcessorCustomerService)) {
      throw new \RuntimeException('Service is not of expected type');
    }
    $this->service = $service;

    // Create test contact
    $contact = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Test')
      ->addValue('last_name', 'Customer')
      ->execute()
      ->first();

    if ($contact === NULL || !isset($contact['id'])) {
      throw new \RuntimeException('Failed to create test contact');
    }

    $this->contactId = (int) $contact['id'];

    // Create test payment processor using Dummy type (built-in test processor)
    $processor = PaymentProcessor::create(FALSE)
      ->addValue('name', 'Test Processor')
      ->addValue('payment_processor_type_id:name', 'Dummy')
      ->addValue('class_name', 'Payment_Dummy')
      ->addValue('is_active', 1)
      ->addValue('is_test', 0)
      ->execute()
      ->first();

    if ($processor === NULL || !isset($processor['id'])) {
      throw new \RuntimeException('Failed to create test payment processor');
    }

    $this->paymentProcessorId = (int) $processor['id'];
  }

  /**
   * Tests getting existing customer ID.
   */
  public function testGetExistingCustomerId(): void {
    $processorCustomerId = 'cus_test_12345';

    // Store customer
    $this->service->storeCustomerId($this->contactId, $this->paymentProcessorId, $processorCustomerId);

    // Get customer
    $result = $this->service->getCustomerId($this->contactId, $this->paymentProcessorId);

    $this->assertEquals($processorCustomerId, $result);
  }

  /**
   * Tests getting non-existent customer ID returns NULL.
   */
  public function testGetNonExistentCustomerIdReturnsNull(): void {
    $result = $this->service->getCustomerId($this->contactId, $this->paymentProcessorId);

    $this->assertNull($result);
  }

  /**
   * Tests storing new customer ID.
   */
  public function testStoreNewCustomerId(): void {
    $processorCustomerId = 'cus_test_67890';

    $this->service->storeCustomerId($this->contactId, $this->paymentProcessorId, $processorCustomerId);

    // Verify stored
    $stored = PaymentProcessorCustomer::get(FALSE)
      ->addWhere('contact_id', '=', $this->contactId)
      ->addWhere('payment_processor_id', '=', $this->paymentProcessorId)
      ->execute()
      ->first();

    $this->assertNotNull($stored);
    $this->assertEquals($processorCustomerId, $stored['processor_customer_id']);
  }

  /**
   * Tests storing duplicate customer ID updates existing record.
   */
  public function testStoreDuplicateCustomerIdUpdates(): void {
    $oldCustomerId = 'cus_old_123';
    $newCustomerId = 'cus_new_456';

    // Store first customer
    $this->service->storeCustomerId($this->contactId, $this->paymentProcessorId, $oldCustomerId);

    // Store second customer (should update)
    $this->service->storeCustomerId($this->contactId, $this->paymentProcessorId, $newCustomerId);

    // Verify updated
    $result = $this->service->getCustomerId($this->contactId, $this->paymentProcessorId);
    $this->assertEquals($newCustomerId, $result);

    // Verify only one record exists
    $count = PaymentProcessorCustomer::get(FALSE)
      ->addWhere('contact_id', '=', $this->contactId)
      ->addWhere('payment_processor_id', '=', $this->paymentProcessorId)
      ->execute()
      ->count();

    $this->assertEquals(1, $count);
  }

  /**
   * Tests getOrCreateCustomerId returns existing customer.
   */
  public function testGetOrCreateReturnsExistingCustomer(): void {
    $existingCustomerId = 'cus_existing_789';

    // Store existing customer
    $this->service->storeCustomerId($this->contactId, $this->paymentProcessorId, $existingCustomerId);

    // Get or create (should return existing)
    $callbackCalled = FALSE;
    $result = $this->service->getOrCreateCustomerId(
      $this->contactId,
      $this->paymentProcessorId,
      function () use (&$callbackCalled) {
        $callbackCalled = TRUE;
        return 'cus_new_should_not_be_created';
      }
    );

    $this->assertEquals($existingCustomerId, $result);
    $this->assertFalse($callbackCalled, 'Callback should not be called when customer exists');
  }

  /**
   * Tests getOrCreateCustomerId creates new customer.
   */
  public function testGetOrCreateCreatesNewCustomer(): void {
    $newCustomerId = 'cus_new_101';

    $callbackCalled = FALSE;
    $result = $this->service->getOrCreateCustomerId(
      $this->contactId,
      $this->paymentProcessorId,
      function () use (&$callbackCalled, $newCustomerId) {
        $callbackCalled = TRUE;
        return $newCustomerId;
      }
    );

    $this->assertEquals($newCustomerId, $result);
    $this->assertTrue($callbackCalled, 'Callback should be called when customer does not exist');

    // Verify stored
    $stored = $this->service->getCustomerId($this->contactId, $this->paymentProcessorId);
    $this->assertEquals($newCustomerId, $stored);
  }

  /**
   * Tests getOrCreateCustomerId throws exception when callback fails.
   */
  public function testGetOrCreateThrowsExceptionWhenCallbackFails(): void {
    $this->expectException(PaymentProcessorCustomerException::class);
    $this->expectExceptionMessage('Failed to create customer');

    $this->service->getOrCreateCustomerId(
      $this->contactId,
      $this->paymentProcessorId,
      function () {
        throw new \Exception('Stripe API error');
      }
    );
  }

  /**
   * Tests getOrCreateCustomerId throws exception when callback returns empty.
   */
  public function testGetOrCreateThrowsExceptionWhenCallbackReturnsEmpty(): void {
    $this->expectException(PaymentProcessorCustomerException::class);
    $this->expectExceptionMessage('must return a non-empty string customer ID');

    $this->service->getOrCreateCustomerId(
      $this->contactId,
      $this->paymentProcessorId,
      function () {
        return '';
      }
    );
  }

  /**
   * Tests deleting customer ID.
   */
  public function testDeleteCustomerId(): void {
    $processorCustomerId = 'cus_delete_123';

    // Store customer
    $this->service->storeCustomerId($this->contactId, $this->paymentProcessorId, $processorCustomerId);

    // Delete customer
    $deleted = $this->service->deleteCustomerId($this->contactId, $this->paymentProcessorId);

    $this->assertTrue($deleted);

    // Verify deleted
    $result = $this->service->getCustomerId($this->contactId, $this->paymentProcessorId);
    $this->assertNull($result);
  }

  /**
   * Tests deleting non-existent customer returns FALSE.
   */
  public function testDeleteNonExistentCustomerReturnsFalse(): void {
    $deleted = $this->service->deleteCustomerId($this->contactId, $this->paymentProcessorId);

    $this->assertFalse($deleted);
  }

  /**
   * Tests service is accessible via container.
   */
  public function testServiceAccessibleViaContainer(): void {
    $service = \Civi::service('paymentprocessingcore.payment_processor_customer');

    $this->assertInstanceOf(PaymentProcessorCustomerService::class, $service);
  }

}
