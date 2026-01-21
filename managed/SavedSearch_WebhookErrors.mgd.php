<?php

use CRM_Paymentprocessingcore_ExtensionUtil as E;

/**
 * SearchKit: Webhook Errors.
 *
 * Displays webhooks with error or permanent_error status for investigation.
 * Permission-gated for platform operators only.
 */
return [
  [
    'name' => 'SavedSearch_Webhook_Errors',
    'entity' => 'SavedSearch',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Webhook_Errors',
        'label' => E::ts('Webhook Errors'),
        'api_entity' => 'PaymentWebhook',
        'api_params' => [
          'version' => 4,
          'select' => [
            'id',
            'event_id',
            'processor_type',
            'event_type',
            'status:label',
            'attempts',
            'error_log',
            'next_retry_at',
            'created_date',
            'processed_at',
          ],
          'where' => [
            ['status', 'IN', ['error', 'permanent_error']],
          ],
          'orderBy' => [
            'created_date' => 'DESC',
          ],
        ],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SavedSearch_Webhook_Errors_SearchDisplay',
    'entity' => 'SearchDisplay',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Webhook_Errors_Table',
        'label' => E::ts('Webhook Errors'),
        'saved_search_id.name' => 'Webhook_Errors',
        'type' => 'table',
        'settings' => [
          'actions' => TRUE,
          'limit' => 50,
          'classes' => ['table', 'table-striped'],
          'pager' => ['show_count' => TRUE, 'expose_limit' => TRUE],
          'columns' => [
            [
              'type' => 'field',
              'key' => 'id',
              'label' => E::ts('ID'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'event_id',
              'label' => E::ts('Event ID'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'processor_type',
              'label' => E::ts('Processor'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'event_type',
              'label' => E::ts('Event Type'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'status:label',
              'label' => E::ts('Status'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'attempts',
              'label' => E::ts('Attempts'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'error_log',
              'label' => E::ts('Error Details'),
              'sortable' => FALSE,
            ],
            [
              'type' => 'field',
              'key' => 'next_retry_at',
              'label' => E::ts('Next Retry'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'created_date',
              'label' => E::ts('Received'),
              'sortable' => TRUE,
            ],
          ],
        ],
        'acl_bypass' => FALSE,
        'permission' => ['administer payment webhook health'],
      ],
      'match' => ['saved_search_id', 'name'],
    ],
  ],
];
