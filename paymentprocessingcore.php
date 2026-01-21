<?php

require_once 'paymentprocessingcore.civix.php';
// phpcs:disable
use CRM_Paymentprocessingcore_ExtensionUtil as E;
// phpcs:enable

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function paymentprocessingcore_civicrm_config(&$config): void {
  _paymentprocessingcore_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function paymentprocessingcore_civicrm_install(): void {
  _paymentprocessingcore_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function paymentprocessingcore_civicrm_enable(): void {
  _paymentprocessingcore_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_container().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_container/
 */
function paymentprocessingcore_civicrm_container($container): void {
  $containers = [
    new \Civi\Paymentprocessingcore\Hook\Container\ServiceContainer($container),
  ];

  foreach ($containers as $containerInstance) {
    $containerInstance->register();
  }
}

/**
 * Implements hook_civicrm_permission().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_permission/
 */
function paymentprocessingcore_civicrm_permission(&$permissions): void {
  $hooks = [
    new CRM_Paymentprocessingcore_Hook_Permission_WebhookHealthPermission($permissions),
  ];

  foreach ($hooks as $hook) {
    $hook->run();
  }
}

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_preProcess
 */
//function paymentprocessingcore_civicrm_preProcess($formName, &$form): void {
//
//}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_navigationMenu
 */
//function paymentprocessingcore_civicrm_navigationMenu(&$menu): void {
//  _paymentprocessingcore_civix_insert_navigation_menu($menu, 'Mailings', [
//    'label' => E::ts('New subliminal message'),
//    'name' => 'mailing_subliminal_message',
//    'url' => 'civicrm/mailing/subliminal',
//    'permission' => 'access CiviMail',
//    'operator' => 'OR',
//    'separator' => 0,
//  ]);
//  _paymentprocessingcore_civix_navigationMenu($menu);
//}
