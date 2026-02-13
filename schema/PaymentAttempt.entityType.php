<?php
use CRM_Paymentprocessingcore_ExtensionUtil as E;

return [
  'name' => 'PaymentAttempt',
  'table' => 'civicrm_payment_attempt',
  'class' => 'CRM_Paymentprocessingcore_DAO_PaymentAttempt',
  'getInfo' => fn() => [
    'title' => E::ts('Payment Attempt'),
    'title_plural' => E::ts('Payment Attempts'),
    'description' => E::ts('Tracks payment attempts across all processors (Stripe, GoCardless, ITAS, etc.)'),
    'log' => TRUE,
  ],
  'getIndices' => fn() => [
    'index_contribution_id' => [
      'fields' => [
        'contribution_id' => TRUE,
      ],
      'unique' => TRUE,
    ],
    'index_processor_type' => [
      'fields' => [
        'processor_type' => TRUE,
      ],
    ],
    'index_processor_session' => [
      'fields' => [
        'processor_session_id' => TRUE,
        'processor_type' => TRUE,
      ],
    ],
    'index_processor_payment' => [
      'fields' => [
        'processor_payment_id' => TRUE,
        'processor_type' => TRUE,
      ],
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
    'contribution_id' => [
      'title' => E::ts('Contribution ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      'required' => TRUE,
      'description' => E::ts('FK to Contribution'),
      'input_attrs' => [
        'label' => E::ts('Contribution'),
      ],
      'entity_reference' => [
        'entity' => 'Contribution',
        'key' => 'id',
        'on_delete' => 'CASCADE',
      ],
    ],
    'contact_id' => [
      'title' => E::ts('Contact ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      'description' => E::ts('FK to Contact (donor)'),
      'input_attrs' => [
        'label' => E::ts('Contact'),
      ],
      'entity_reference' => [
        'entity' => 'Contact',
        'key' => 'id',
        'on_delete' => 'SET NULL',
      ],
    ],
    'payment_processor_id' => [
      'title' => E::ts('Payment Processor ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Select',
      'description' => E::ts('FK to Payment Processor'),
      'input_attrs' => [
        'label' => E::ts('Payment Processor'),
      ],
      'entity_reference' => [
        'entity' => 'PaymentProcessor',
        'key' => 'id',
        'on_delete' => 'SET NULL',
      ],
    ],
    'processor_type' => [
      'title' => E::ts('Processor Type'),
      'sql_type' => 'varchar(50)',
      'input_type' => 'Text',
      'required' => TRUE,
      'description' => E::ts('Processor type: \'stripe\', \'gocardless\', \'itas\', etc.'),
      'input_attrs' => [
        'label' => E::ts('Processor Type'),
      ],
    ],
    'processor_session_id' => [
      'title' => E::ts('Processor Session ID'),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'description' => E::ts('Processor session ID (cs_... for Stripe, mandate_... for GoCardless)'),
      'input_attrs' => [
        'label' => E::ts('Processor Session ID'),
      ],
    ],
    'processor_payment_id' => [
      'title' => E::ts('Processor Payment ID'),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'description' => E::ts('Processor payment ID (pi_... for Stripe, payment_... for GoCardless)'),
      'input_attrs' => [
        'label' => E::ts('Processor Payment ID'),
      ],
    ],
    'status' => [
      'title' => E::ts('Status'),
      'sql_type' => 'varchar(25)',
      'input_type' => 'Select',
      'required' => TRUE,
      'description' => E::ts('Attempt status: pending, completed, failed, cancelled'),
      'default' => 'pending',
      'input_attrs' => [
        'label' => E::ts('Status'),
      ],
      'pseudoconstant' => [
        'callback' => 'CRM_Paymentprocessingcore_BAO_PaymentAttempt::getStatuses',
      ],
    ],
    'created_date' => [
      'title' => E::ts('Created Date'),
      'sql_type' => 'timestamp',
      'input_type' => 'Select Date',
      'required' => TRUE,
      'description' => E::ts('When attempt was created'),
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
