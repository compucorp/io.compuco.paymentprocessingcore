<?php

use CRM_Paymentprocessingcore_ExtensionUtil as E;

/**
 * Navigation: Webhook Dashboard.
 *
 * Adds navigation menu item under Administer for accessing webhook dashboards.
 * Permission-gated for platform operators only.
 */
return [
  [
    'name' => 'Navigation_Webhook_Dashboard',
    'entity' => 'Navigation',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'label' => E::ts('Webhook Dashboard'),
        'name' => 'webhook_dashboard',
        'url' => 'civicrm/searchkit#/list/Webhook_Status_Overview',
        'icon' => 'crm-i fa-heartbeat',
        'permission' => [
          'administer payment webhook health',
        ],
        'permission_operator' => 'OR',
        'parent_id.name' => 'System Settings',
        'is_active' => TRUE,
        'weight' => 100,
      ],
      'match' => ['domain_id', 'name'],
    ],
  ],
];
