<?php

use Civi\Paymentprocessingcore\Service\InstalmentGenerationService;

/**
 * InstalmentGenerator.Run API.
 *
 * Generate instalment contributions for due recurring contributions
 * (non-membership). Creates Pending contribution records without
 * making any payment processor calls.
 *
 * @param array $params
 *   API parameters:
 *   - processor_type: (optional) Payment processor type name (default: "Stripe").
 *   - batch_size: (optional) Max records to process (default: 500).
 *
 * @return array
 *   API result with processing summary.
 *
 * @throws \CRM_Core_Exception
 */
function civicrm_api3_instalment_generator_Run(array $params): array {
  $processorType = $params['processor_type'] ?? InstalmentGenerationService::DEFAULT_PROCESSOR_TYPE;
  $batchSize = (int) ($params['batch_size'] ?? InstalmentGenerationService::DEFAULT_BATCH_SIZE);

  $service = \Civi::service('paymentprocessingcore.instalment_generation');
  if (!$service instanceof InstalmentGenerationService) {
    throw new \CRM_Core_Exception('instalment_generation service must return InstalmentGenerationService');
  }

  $result = $service->generateInstalments($processorType, $batchSize);

  return civicrm_api3_create_success($result, $params, 'InstalmentGenerator', 'Run');
}

/**
 * InstalmentGenerator.Run API specification.
 *
 * @param array $spec
 *   API specification array.
 */
function _civicrm_api3_instalment_generator_Run_spec(array &$spec): void {
  $spec['processor_type'] = [
    'title' => 'Processor Type',
    'description' => 'Payment processor type name. Default: "Stripe".',
    'type' => CRM_Utils_Type::T_STRING,
    'api.default' => InstalmentGenerationService::DEFAULT_PROCESSOR_TYPE,
  ];
  $spec['batch_size'] = [
    'title' => 'Batch Size',
    'description' => 'Maximum number of recurring contributions to process. Default: 500.',
    'type' => CRM_Utils_Type::T_INT,
    'api.default' => InstalmentGenerationService::DEFAULT_BATCH_SIZE,
  ];
}
