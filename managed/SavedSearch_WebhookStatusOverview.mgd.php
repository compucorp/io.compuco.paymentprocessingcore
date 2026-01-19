<?php

use CRM_Paymentprocessingcore_ExtensionUtil as E;

/**
 * SearchKit: Webhook Status Overview.
 *
 * Displays webhook counts grouped by processor type and status.
 * Permission-gated for platform operators only.
 */
return [
  [
    'name' => 'SavedSearch_Webhook_Status_Overview',
    'entity' => 'SavedSearch',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Webhook_Status_Overview',
        'label' => E::ts('Webhook Status Overview'),
        'api_entity' => 'PaymentWebhook',
        'api_params' => [
          'version' => 4,
          'select' => [
            'processor_type',
            'status:label',
            'COUNT(id) AS count',
          ],
          'groupBy' => [
            'processor_type',
            'status',
          ],
          'orderBy' => [
            'processor_type' => 'ASC',
            'status' => 'ASC',
          ],
        ],
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'SavedSearch_Webhook_Status_Overview_SearchDisplay',
    'entity' => 'SearchDisplay',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'Webhook_Status_Overview_Table',
        'label' => E::ts('Webhook Status Overview'),
        'saved_search_id.name' => 'Webhook_Status_Overview',
        'type' => 'table',
        'settings' => [
          'actions' => FALSE,
          'limit' => 50,
          'classes' => ['table', 'table-striped'],
          'pager' => ['show_count' => TRUE],
          'columns' => [
            [
              'type' => 'field',
              'key' => 'processor_type',
              'label' => E::ts('Processor'),
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
              'key' => 'count',
              'label' => E::ts('Count'),
              'sortable' => TRUE,
            ],
          ],
        ],
        'acl_bypass' => FALSE,
      ],
      'match' => ['saved_search_id', 'name'],
    ],
  ],
];
