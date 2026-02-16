<?php

use Civi\Paymentprocessingcore\Service\PaymentAttemptReconcileService;

/**
 * PaymentAttemptReconcile.Run API.
 *
 * Finds stuck PaymentAttempt records (status = 'processing' beyond threshold)
 * and dispatches events for processor-specific extensions to reconcile
 * against their payment processor APIs.
 *
 * @param array $params
 *   API parameters:
 *   - processor_parameters: (required) Processor configs in format
 *     "[Stripe,3],[GoCardless,7]" where each pair is [ProcessorType,ThresholdDays].
 *   - batch_size: (optional, default: 100) Max total attempts to reconcile.
 *
 * @return array
 *   API result with reconciliation summary.
 *
 * @throws \CRM_Core_Exception
 */
function civicrm_api3_payment_attempt_reconcile_Run(array $params): array {
  $processorConfigs = _civicrm_api3_payment_attempt_reconcile_parse_processor_parameters(
    strval($params['processor_parameters'] ?? '')
  );

  if (empty($processorConfigs)) {
    throw new \CRM_Core_Exception(
      'processor_parameters is required. Format: "[Stripe,3],[GoCardless,7]" where each pair is [ProcessorType,ThresholdDays].'
    );
  }

  $batchSize = (int) ($params['batch_size'] ?? 100);

  if ($batchSize <= 0) {
    throw new \CRM_Core_Exception('batch_size must be a positive integer');
  }

  $maxRetryCount = (int) ($params['max_retry_count'] ?? 3);

  $service = \Civi::service('paymentprocessingcore.payment_attempt_reconcile');
  if (!$service instanceof PaymentAttemptReconcileService) {
    throw new \CRM_Core_Exception('payment_attempt_reconcile service must return PaymentAttemptReconcileService');
  }

  $result = $service->reconcileStuckAttempts($processorConfigs, $batchSize, $maxRetryCount);

  return civicrm_api3_create_success($result, $params, 'PaymentAttemptReconcile', 'Run');
}

/**
 * Parse processor_parameters string into associative array.
 *
 * Input format: "[Stripe,3],[GoCardless,7]"
 * Output: ['Stripe' => 3, 'GoCardless' => 7]
 *
 * @param string $paramString
 *   The processor parameters string.
 *
 * @return array
 *   Processor type to threshold days mapping.
 *
 * @phpstan-return array<string, int>
 */
function _civicrm_api3_payment_attempt_reconcile_parse_processor_parameters(string $paramString): array {
  $configs = [];

  if (empty($paramString)) {
    return $configs;
  }

  $matches = [];
  preg_match_all('/\[([^,\]]+),\s*(\d+)\]/', $paramString, $matches, PREG_SET_ORDER);

  foreach ($matches as $match) {
    $processorType = trim($match[1]);
    $thresholdDays = (int) $match[2];

    if ($processorType !== '' && $thresholdDays > 0) {
      $configs[$processorType] = $thresholdDays;
    }
  }

  return $configs;
}

/**
 * PaymentAttemptReconcile.Run API specification.
 *
 * @param array $spec
 *   API specification array.
 */
function _civicrm_api3_payment_attempt_reconcile_Run_spec(array &$spec): void {
  $spec['processor_parameters'] = [
    'title' => 'Processor Parameters',
    'description' => 'Processor configurations in format "[Stripe,3],[GoCardless,7]". Each pair specifies [ProcessorType,ThresholdDays] — the number of days a payment attempt must be stuck before reconciliation.',
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => TRUE,
  ];
  $spec['batch_size'] = [
    'title' => 'Batch Size',
    'description' => 'Maximum total number of attempts to reconcile across all processor types.',
    'type' => CRM_Utils_Type::T_INT,
    'api.required' => FALSE,
    'api.default' => 100,
  ];
  $spec['max_retry_count'] = [
    'title' => 'Max Retry Count',
    'description' => 'Maximum number of retries before marking a recurring contribution as failed.',
    'type' => CRM_Utils_Type::T_INT,
    'api.required' => FALSE,
    'api.default' => 3,
  ];
}
