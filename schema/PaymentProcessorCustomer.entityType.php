<?php
use CRM_Paymentprocessingcore_ExtensionUtil as E;

return [
  'name' => 'PaymentProcessorCustomer',
  'table' => 'civicrm_payment_processor_customer',
  'class' => 'CRM_Paymentprocessingcore_DAO_PaymentProcessorCustomer',
  'getInfo' => fn() => [
    'title' => E::ts('Payment Processor Customer'),
    'title_plural' => E::ts('Payment Processor Customers'),
    'description' => E::ts('Stores payment processor customer IDs for all processors (Stripe, GoCardless, ITAS, etc.)'),
    'log' => TRUE,
  ],
  'getIndices' => fn() => [
    'index_payment_processor_id' => [
      'fields' => [
        'payment_processor_id' => TRUE,
      ],
    ],
    'index_processor_customer_id' => [
      'fields' => [
        'processor_customer_id' => TRUE,
      ],
    ],
    'index_contact_id' => [
      'fields' => [
        'contact_id' => TRUE,
      ],
    ],
    'unique_contact_processor' => [
      'fields' => [
        'contact_id' => TRUE,
        'payment_processor_id' => TRUE,
      ],
      'unique' => TRUE,
    ],
    'unique_processor_customer' => [
      'fields' => [
        'payment_processor_id' => TRUE,
        'processor_customer_id' => TRUE,
      ],
      'unique' => TRUE,
    ],
  ],
  'getFields' => fn() => [
    'id' => [
      'title' => E::ts('ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'description' => E::ts('Unique ID'),
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
    ],
    'payment_processor_id' => [
      'title' => E::ts('Payment Processor ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Select',
      'required' => TRUE,
      'description' => E::ts('FK to Payment Processor'),
      'input_attrs' => [
        'label' => E::ts('Payment Processor'),
      ],
      'entity_reference' => [
        'entity' => 'PaymentProcessor',
        'key' => 'id',
        'on_delete' => 'CASCADE',
      ],
    ],
    'processor_customer_id' => [
      'title' => E::ts('Processor Customer ID'),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'required' => TRUE,
      'description' => E::ts('Customer ID from payment processor (e.g., cus_... for Stripe, cu_... for GoCardless)'),
      'input_attrs' => [
        'label' => E::ts('Processor Customer ID'),
      ],
    ],
    'contact_id' => [
      'title' => E::ts('Contact ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      'required' => TRUE,
      'description' => E::ts('FK to Contact'),
      'input_attrs' => [
        'label' => E::ts('Contact'),
      ],
      'entity_reference' => [
        'entity' => 'Contact',
        'key' => 'id',
        'on_delete' => 'CASCADE',
      ],
    ],
    'created_date' => [
      'title' => E::ts('Created Date'),
      'sql_type' => 'timestamp',
      'input_type' => 'Select Date',
      'required' => TRUE,
      'description' => E::ts('When customer record was created'),
      'default' => 'CURRENT_TIMESTAMP',
      'input_attrs' => [
        'label' => E::ts('Created Date'),
      ],
    ],
    'updated_date' => [
      'title' => E::ts('Updated Date'),
      'sql_type' => 'timestamp',
      'input_type' => 'Select Date',
      'required' => TRUE,
      'description' => E::ts('Last updated'),
      'default' => 'CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
      'input_attrs' => [
        'label' => E::ts('Updated Date'),
      ],
    ],
  ],
];
