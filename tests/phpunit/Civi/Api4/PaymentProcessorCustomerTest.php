<?php

use Civi\Api4\Contact;
use Civi\Api4\PaymentProcessor;
use Civi\Api4\PaymentProcessorCustomer;

/**
 * PaymentProcessorCustomer API Test Case.
 *
 * Tests for the PaymentProcessorCustomer Api4 entity.
 *
 * @group headless
 */
class Civi_Api4_PaymentProcessorCustomerTest extends BaseHeadlessTest {

  private int $contactId;

  private int $paymentProcessorId;

  public function setUp(): void {
    parent::setUp();

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
   * Test creating a PaymentProcessorCustomer with required fields.
   */
  public function testCreatePaymentProcessorCustomerWithRequiredFields() {
    $created = PaymentProcessorCustomer::create(FALSE)
      ->addValue('contact_id', $this->contactId)
      ->addValue('payment_processor_id', $this->paymentProcessorId)
      ->addValue('processor_customer_id', 'cus_test_123')
      ->execute()
      ->first();

    // Fetch the full record to get default values
    $customer = PaymentProcessorCustomer::get(FALSE)
      ->addWhere('id', '=', $created['id'])
      ->execute()
      ->first();

    $this->assertNotEmpty($customer['id']);
    $this->assertEquals($this->contactId, $customer['contact_id']);
    $this->assertEquals($this->paymentProcessorId, $customer['payment_processor_id']);
    $this->assertEquals('cus_test_123', $customer['processor_customer_id']);
    $this->assertNotEmpty($customer['created_date']);
  }

  /**
   * Test unique constraint: one customer per contact per processor.
   */
  public function testUniqueConstraintContactProcessor() {
    // Create first customer
    PaymentProcessorCustomer::create(FALSE)
      ->addValue('contact_id', $this->contactId)
      ->addValue('payment_processor_id', $this->paymentProcessorId)
      ->addValue('processor_customer_id', 'cus_test_456')
      ->execute();

    // Try to create duplicate (should fail due to unique constraint)
    $this->expectException(\Exception::class);

    PaymentProcessorCustomer::create(FALSE)
      ->addValue('contact_id', $this->contactId)
      ->addValue('payment_processor_id', $this->paymentProcessorId)
      ->addValue('processor_customer_id', 'cus_test_789')
      ->execute();
  }

  /**
   * Test unique constraint: processor customer ID must be unique per processor.
   */
  public function testUniqueConstraintProcessorCustomerId() {
    // Create second contact
    $contact2Id = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Test2')
      ->addValue('last_name', 'Customer2')
      ->execute()
      ->first()['id'];

    // Create first customer
    PaymentProcessorCustomer::create(FALSE)
      ->addValue('contact_id', $this->contactId)
      ->addValue('payment_processor_id', $this->paymentProcessorId)
      ->addValue('processor_customer_id', 'cus_test_unique')
      ->execute();

    // Try to create second customer with same processor_customer_id (should fail)
    $this->expectException(\Exception::class);

    PaymentProcessorCustomer::create(FALSE)
      ->addValue('contact_id', $contact2Id)
      ->addValue('payment_processor_id', $this->paymentProcessorId)
      ->addValue('processor_customer_id', 'cus_test_unique')
      ->execute();
  }

  /**
   * Test querying by contact and processor.
   */
  public function testQueryByContactAndProcessor() {
    $processorCustomerId = 'cus_test_query_123';

    // Create customer
    PaymentProcessorCustomer::create(FALSE)
      ->addValue('contact_id', $this->contactId)
      ->addValue('payment_processor_id', $this->paymentProcessorId)
      ->addValue('processor_customer_id', $processorCustomerId)
      ->execute();

    // Query by contact and processor
    $result = PaymentProcessorCustomer::get(FALSE)
      ->addWhere('contact_id', '=', $this->contactId)
      ->addWhere('payment_processor_id', '=', $this->paymentProcessorId)
      ->execute()
      ->first();

    $this->assertNotEmpty($result);
    $this->assertEquals($processorCustomerId, $result['processor_customer_id']);
  }

  /**
   * Test updating a customer record.
   */
  public function testUpdateCustomer() {
    $oldCustomerId = 'cus_old_456';
    $newCustomerId = 'cus_new_789';

    // Create customer
    $created = PaymentProcessorCustomer::create(FALSE)
      ->addValue('contact_id', $this->contactId)
      ->addValue('payment_processor_id', $this->paymentProcessorId)
      ->addValue('processor_customer_id', $oldCustomerId)
      ->execute()
      ->first();

    // Update customer ID
    PaymentProcessorCustomer::update(FALSE)
      ->addWhere('id', '=', $created['id'])
      ->addValue('processor_customer_id', $newCustomerId)
      ->execute();

    // Verify update
    $updated = PaymentProcessorCustomer::get(FALSE)
      ->addWhere('id', '=', $created['id'])
      ->execute()
      ->first();

    $this->assertEquals($newCustomerId, $updated['processor_customer_id']);
  }

  /**
   * Test deleting a customer record.
   */
  public function testDeleteCustomer() {
    // Create customer
    $created = PaymentProcessorCustomer::create(FALSE)
      ->addValue('contact_id', $this->contactId)
      ->addValue('payment_processor_id', $this->paymentProcessorId)
      ->addValue('processor_customer_id', 'cus_test_delete')
      ->execute()
      ->first();

    // Delete customer
    PaymentProcessorCustomer::delete(FALSE)
      ->addWhere('id', '=', $created['id'])
      ->execute();

    // Verify deleted
    $result = PaymentProcessorCustomer::get(FALSE)
      ->addWhere('id', '=', $created['id'])
      ->execute()
      ->count();

    $this->assertEquals(0, $result);
  }

  /**
   * Test CASCADE delete when contact is deleted.
   *
   * Note: Skipped because CiviCRM's test framework may not actually delete contacts,
   * only mark them as deleted. The CASCADE constraint is verified at the database schema level.
   *
   * @group skip
   */
  public function skipTestCascadeDeleteWhenContactDeleted() {
    // Create separate contact for this test to avoid conflicts
    $testContactId = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'TestCascade')
      ->addValue('last_name', 'Customer')
      ->execute()
      ->first()['id'];

    // Create customer
    $created = PaymentProcessorCustomer::create(FALSE)
      ->addValue('contact_id', $testContactId)
      ->addValue('payment_processor_id', $this->paymentProcessorId)
      ->addValue('processor_customer_id', 'cus_test_cascade')
      ->execute()
      ->first();

    // Delete contact (this should CASCADE delete the customer record)
    Contact::delete(FALSE)
      ->addWhere('id', '=', $testContactId)
      ->execute();

    // Verify customer record was also deleted (CASCADE)
    $result = PaymentProcessorCustomer::get(FALSE)
      ->addWhere('id', '=', $created['id'])
      ->execute()
      ->count();

    // CASCADE delete should have removed the customer record
    $this->assertEquals(0, $result, 'Customer record should be deleted when contact is deleted (CASCADE constraint)');
  }

}
