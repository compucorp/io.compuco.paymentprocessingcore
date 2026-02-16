<?php
use CRM_Paymentprocessingcore_ExtensionUtil as E;

return [
  'name' => 'PaymentWebhook',
  'table' => 'civicrm_payment_webhook',
  'class' => 'CRM_Paymentprocessingcore_DAO_PaymentWebhook',
  'getInfo' => fn() => [
    'title' => E::ts('Payment Webhook'),
    'title_plural' => E::ts('Payment Webhooks'),
    'description' => E::ts('Webhook event log for de-duplication and idempotency across all processors'),
    'log' => TRUE,
  ],
  'getIndices' => fn() => [
    'UI_event_processor' => [
      'fields' => [
        'event_id' => TRUE,
        'processor_type' => TRUE,
      ],
      'unique' => TRUE,
    ],
    'index_event_type' => [
      'fields' => [
        'event_type' => TRUE,
      ],
    ],
    'index_status_retry' => [
      'fields' => [
        'status' => TRUE,
        'next_retry_at' => TRUE,
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
    'event_id' => [
      'title' => E::ts('Event ID'),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'required' => TRUE,
      'description' => E::ts('Processor event ID (evt_... for Stripe, evt_... for GoCardless)'),
      'input_attrs' => [
        'label' => E::ts('Event ID'),
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
    'event_type' => [
      'title' => E::ts('Event Type'),
      'sql_type' => 'varchar(100)',
      'input_type' => 'Text',
      'required' => TRUE,
      'description' => E::ts('Event type (e.g. checkout.session.completed, payment_intent.succeeded)'),
      'input_attrs' => [
        'label' => E::ts('Event Type'),
      ],
    ],
    'payment_attempt_id' => [
      'title' => E::ts('Payment Attempt ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      'description' => E::ts('FK to Payment Attempt'),
      'input_attrs' => [
        'label' => E::ts('Payment Attempt'),
      ],
      'entity_reference' => [
        'entity' => 'PaymentAttempt',
        'key' => 'id',
        'on_delete' => 'SET NULL',
      ],
    ],
    'status' => [
      'title' => E::ts('Status'),
      'sql_type' => 'varchar(25)',
      'input_type' => 'Select',
      'required' => TRUE,
      'description' => E::ts('Processing status: new, processing, processed, error, permanent_error'),
      'default' => 'new',
      'input_attrs' => [
        'label' => E::ts('Status'),
      ],
      'pseudoconstant' => [
        'callback' => 'CRM_Paymentprocessingcore_BAO_PaymentWebhook::getStatuses',
      ],
    ],
    'attempts' => [
      'title' => E::ts('Attempts'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'description' => E::ts('Number of processing attempts'),
      'default' => 0,
      'input_attrs' => [
        'label' => E::ts('Attempts'),
      ],
    ],
    'next_retry_at' => [
      'title' => E::ts('Next Retry At'),
      'sql_type' => 'timestamp',
      'input_type' => 'Select Date',
      'description' => E::ts('When to retry processing (for exponential backoff)'),
      'input_attrs' => [
        'label' => E::ts('Next Retry At'),
      ],
    ],
    'result' => [
      'title' => E::ts('Result'),
      'sql_type' => 'varchar(50)',
      'input_type' => 'Text',
      'description' => E::ts('Processing result: applied, noop, ignored_out_of_order, error'),
      'input_attrs' => [
        'label' => E::ts('Result'),
      ],
    ],
    'error_log' => [
      'title' => E::ts('Error Log'),
      'sql_type' => 'text',
      'input_type' => 'TextArea',
      'description' => E::ts('Error details if processing failed'),
      'input_attrs' => [
        'label' => E::ts('Error Log'),
      ],
    ],
    'processing_started_at' => [
      'title' => E::ts('Processing Started At'),
      'sql_type' => 'timestamp',
      'input_type' => 'Select Date',
      'description' => E::ts('When webhook entered processing state (for stuck webhook detection)'),
      'input_attrs' => [
        'label' => E::ts('Processing Started At'),
      ],
    ],
    'processed_at' => [
      'title' => E::ts('Processed At'),
      'sql_type' => 'timestamp',
      'input_type' => 'Select Date',
      'description' => E::ts('When event was processed'),
      'input_attrs' => [
        'label' => E::ts('Processed At'),
      ],
    ],
    'created_date' => [
      'title' => E::ts('Created Date'),
      'sql_type' => 'timestamp',
      'input_type' => 'Select Date',
      'required' => TRUE,
      'description' => E::ts('When webhook was received'),
      'default' => 'CURRENT_TIMESTAMP',
      'input_attrs' => [
        'label' => E::ts('Created Date'),
      ],
    ],
  ],
];
