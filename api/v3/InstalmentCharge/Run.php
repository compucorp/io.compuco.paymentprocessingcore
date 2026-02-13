<?php

use Civi\Paymentprocessingcore\Service\InstalmentChargeService;

/**
 * InstalmentCharge.Run API.
 *
 * Charge due instalment contributions by selecting eligible Pending/Partially
 * paid contributions and dispatching events for processor-specific charging.
 *
 * @param array $params
 *   API parameters:
 *   - processor_type: (required) Payment processor type name(s). Can be:
 *     - Single type: "Stripe"
 *     - Comma-separated: "Stripe,GoCardless"
 *     - Array: ["Stripe", "GoCardless"]
 *   - batch_size: (required) Max records to process PER processor type.
 *   - max_retry_count: (optional, default: 3) Max failure count before skipping.
 *
 * @return array
 *   API result with processing summary.
 *
 * @throws \CRM_Core_Exception
 */
function civicrm_api3_instalment_charge_Run(array $params): array {
  // Parse processor_type following CiviCRM patterns.
  $processorTypes = _civicrm_api3_instalment_charge_parse_processor_types($params);

  if (empty($processorTypes)) {
    throw new \CRM_Core_Exception('processor_type is required. Specify which processor type(s) to charge (e.g., "Stripe" or "Stripe,GoCardless").');
  }

  $batchSize = (int) $params['batch_size'];
  $maxRetryCount = (int) ($params['max_retry_count'] ?? 3);

  if ($batchSize <= 0) {
    throw new \CRM_Core_Exception('batch_size must be a positive integer');
  }

  if ($maxRetryCount < 0) {
    throw new \CRM_Core_Exception('max_retry_count must be a non-negative integer');
  }

  $service = \Civi::service('paymentprocessingcore.instalment_charge');
  if (!$service instanceof InstalmentChargeService) {
    throw new \CRM_Core_Exception('instalment_charge service must return InstalmentChargeService');
  }

  $result = $service->chargeInstalments($processorTypes, $batchSize, $maxRetryCount);

  return civicrm_api3_create_success($result, $params, 'InstalmentCharge', 'Run');
}

/**
 * Parse processor_type parameter into array.
 *
 * Handles multiple input formats following CiviCRM patterns:
 * - Already an array: use as-is
 * - Array with 'IN' key: extract values (CiviCRM API convention)
 * - Comma-separated string: split into array
 * - Single string: wrap in array
 *
 * @param array $params
 *   API parameters.
 *
 * @return array<string>
 *   Array of processor type names (may be empty).
 */
function _civicrm_api3_instalment_charge_parse_processor_types(array $params): array {
  if (empty($params['processor_type'])) {
    return [];
  }

  $value = $params['processor_type'];

  // Already an array.
  if (is_array($value)) {
    // Handle CiviCRM API 'IN' format: ['IN' => ['Stripe', 'GoCardless']].
    if (!empty($value['IN'])) {
      $value = $value['IN'];
    }
    // Filter and return.
    return array_values(array_filter(array_map('trim', $value)));
  }

  // String value - could be comma-separated or single value.
  if (is_string($value)) {
    $types = array_map('trim', explode(',', $value));
    return array_values(array_filter($types));
  }

  return [];
}

/**
 * InstalmentCharge.Run API specification.
 *
 * @param array $spec
 *   API specification array.
 */
function _civicrm_api3_instalment_charge_Run_spec(array &$spec): void {
  $spec['processor_type'] = [
    'title' => 'Processor Type',
    'description' => 'Payment processor type name(s) to charge. Comma-separated for multiple (e.g., "Stripe,GoCardless"), or array. Only processors with event subscribers will process charges.',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => TRUE,
  ];
  $spec['batch_size'] = [
    'title' => 'Batch Size',
    'description' => 'Maximum number of contributions to process PER processor type.',
    'type' => CRM_Utils_Type::T_INT,
    'api.required' => TRUE,
  ];
  $spec['max_retry_count'] = [
    'title' => 'Max Retry Count',
    'description' => 'Maximum recurring contribution failure count before skipping.',
    'type' => CRM_Utils_Type::T_INT,
    'api.required' => FALSE,
    'api.default' => 3,
  ];
}
