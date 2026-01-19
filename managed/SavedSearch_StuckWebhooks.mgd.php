<?php

use CRM_Paymentprocessingcore_ExtensionUtil as E;

/**
 * SearchKit: Stuck Webhooks.
 *
 * Displays webhooks stuck in 'processing' state for more than 30 minutes.
 * Permission-gated for platform operators only.
 */
return [
  [
    'name' => 'SavedSearch_Stuck_Webhooks',
    'entity' => 'SavedSearch',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Stuck_Webhooks',
        'label' => E::ts('Stuck Webhooks'),
        'api_entity' => 'PaymentWebhook',
        'api_params' => [
          'version' => 4,
          'select' => [
            'id',
            'event_id',
            'processor_type',
            'event_type',
            'status:label',
            'processing_started_at',
            'attempts',
            'error_log',
            'created_date',
          ],
          'where' => [
            ['status', '=', 'processing'],
            ['processing_started_at', 'IS NOT NULL'],
          ],
          'orderBy' => [
            'processing_started_at' => 'ASC',
          ],
        ],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SavedSearch_Stuck_Webhooks_SearchDisplay',
    'entity' => 'SearchDisplay',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Stuck_Webhooks_Table',
        'label' => E::ts('Stuck Webhooks'),
        'saved_search_id.name' => 'Stuck_Webhooks',
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
              'key' => 'processing_started_at',
              'label' => E::ts('Stuck Since'),
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
              'label' => E::ts('Error'),
              'sortable' => FALSE,
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
