<?php

use Civi\Paymentprocessingcore\Service\WebhookQueueRunnerService;

/**
 * WebhookQueueRunner.Run API.
 *
 * Process queued webhook events from payment processors.
 *
 * By default (processor_type='all'), processes webhooks from ALL registered
 * payment processors (Stripe, GoCardless, Deluxe, etc.) in a single job run.
 *
 * Extensions automatically register themselves via DI at container compile time,
 * so no manual configuration is needed when adding new payment processors.
 *
 * Features:
 * - Batch size limiting to prevent job timeouts (default: 250 per processor)
 * - Automatic retry of failed webhooks with exponential backoff
 * - Recovery of stuck webhooks (processing > 30 minutes)
 *
 * @param array $params
 *   API parameters:
 *   - processor_type: (optional) 'all' (default) to process all registered
 *     processors, or a specific processor type like 'stripe', 'gocardless'.
 *   - batch_size: (optional) Max items to process per processor (default: 250, 0 = unlimited)
 *
 * @return array
 *   API result with processing results keyed by processor type.
 *
 * @throws \CRM_Core_Exception
 */
function civicrm_api3_webhook_queue_runner_Run(array $params): array {
  $processorType = $params['processor_type'] ?? 'all';
  $batchSize = (int) ($params['batch_size'] ?? WebhookQueueRunnerService::DEFAULT_BATCH_SIZE);

  /** @var \Civi\Paymentprocessingcore\Service\WebhookQueueRunnerService $runnerService */
  $runnerService = \Civi::service('paymentprocessingcore.webhook_queue_runner');

  // 'all' processes queues for all registered processors
  if ($processorType === 'all') {
    $result = $runnerService->runAllQueues($batchSize);
  }
  else {
    $result = [$processorType => $runnerService->runQueue($processorType, $batchSize)];
  }

  return civicrm_api3_create_success($result, $params, 'WebhookQueueRunner', 'Run');
}

/**
 * WebhookQueueRunner.Run API specification.
 *
 * @param array $spec
 *   API specification array.
 */
function _civicrm_api3_webhook_queue_runner_Run_spec(array &$spec): void {
  $spec['processor_type'] = [
    'title' => 'Processor Type',
    'description' => 'Payment processor type. Use "all" (default) to process webhooks from all registered processors, or specify one: stripe, gocardless, deluxe, etc.',
    'type' => CRM_Utils_Type::T_STRING,
    'api.default' => 'all',
  ];
  $spec['batch_size'] = [
    'title' => 'Batch Size',
    'description' => 'Maximum number of webhooks to process per processor. Default is 250 to prevent job timeouts. Use 0 for unlimited.',
    'type' => CRM_Utils_Type::T_INT,
    'api.default' => WebhookQueueRunnerService::DEFAULT_BATCH_SIZE,
  ];
}
