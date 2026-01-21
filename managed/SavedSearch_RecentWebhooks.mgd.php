<?php

use CRM_Paymentprocessingcore_ExtensionUtil as E;

/**
 * SearchKit: Recent Webhooks.
 *
 * Displays recently received webhooks across all processors.
 * Permission-gated for platform operators only.
 */
return [
  [
    'name' => 'SavedSearch_Recent_Webhooks',
    'entity' => 'SavedSearch',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Recent_Webhooks',
        'label' => E::ts('Recent Webhooks'),
        'api_entity' => 'PaymentWebhook',
        'api_params' => [
          'version' => 4,
          'select' => [
            'id',
            'event_id',
            'processor_type',
            'event_type',
            'status:label',
            'result',
            'attempts',
            'created_date',
            'processed_at',
          ],
          'orderBy' => [
            'created_date' => 'DESC',
          ],
          'limit' => 100,
        ],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SavedSearch_Recent_Webhooks_SearchDisplay',
    'entity' => 'SearchDisplay',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Recent_Webhooks_Table',
        'label' => E::ts('Recent Webhooks'),
        'saved_search_id.name' => 'Recent_Webhooks',
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
              'key' => 'result',
              'label' => E::ts('Result'),
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
              'key' => 'created_date',
              'label' => E::ts('Received'),
              'sortable' => TRUE,
            ],
            [
              'type' => 'field',
              'key' => 'processed_at',
              'label' => E::ts('Processed'),
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
