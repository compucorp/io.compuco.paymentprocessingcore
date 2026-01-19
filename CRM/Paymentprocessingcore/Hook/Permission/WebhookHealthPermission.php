<?php

use CRM_Paymentprocessingcore_ExtensionUtil as E;

/**
 * Webhook health monitoring permission.
 *
 * Defines the permission for viewing webhook health metrics and dashboards.
 * This permission is intended for platform operators only, not client admins
 * in a SaaS environment.
 *
 * @package CRM_Paymentprocessingcore_Hook_Permission
 */
class CRM_Paymentprocessingcore_Hook_Permission_WebhookHealthPermission {

  /**
   * Permission constant for administering webhook health.
   */
  public const PERMISSION = 'administer payment webhook health';

  /**
   * Reference to permissions array.
   *
   * @var array
   * @phpstan-var array<string, array{label: string, description: string}>
   */
  private array $permissions;

  /**
   * Constructor.
   *
   * @param array $permissions
   *   Reference to the permissions array.
   *
   * @phpstan-param array<string, array{label: string, description: string}> $permissions
   */
  public function __construct(array &$permissions) {
    $this->permissions = &$permissions;
  }

  /**
   * Register the webhook health permission.
   */
  public function run(): void {
    $this->permissions += [
      self::PERMISSION => [
        'label' => E::ts('Payment Processing: Administer webhook health'),
        'description' => E::ts('View webhook health metrics and investigate webhook issues. Platform operators only.'),
      ],
    ];
  }

}
