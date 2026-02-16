<?php

/**
 * Managed entity definition for payment attempt reconciliation scheduled job.
 *
 * This job finds PaymentAttempt records stuck in 'processing' status and
 * dispatches events for processor-specific extensions (Stripe, GoCardless)
 * to reconcile against their payment processor APIs.
 *
 * Parameters:
 * - processor_parameters: (required) Format: "[Stripe,2],[GoCardless,6]"
 *   Each pair specifies [ProcessorType,ThresholdDays].
 * - batch_size: Max total attempts to reconcile (default: 100)
 *
 * @see api/v3/PaymentAttemptReconcile/Run.php
 */
return [
  [
    'name' => 'PaymentAttemptReconcile',
    'entity' => 'Job',
    'params' => [
      'version' => 3,
      'name' => 'Reconcile stuck payment attempts',
      'description' => 'Check payment attempts stuck in "processing" state and reconcile their status with the payment processor.',
      'run_frequency' => 'Daily',
      'api_entity' => 'PaymentAttemptReconcile',
      'api_action' => 'Run',
      'api_version' => 3,
      'parameters' => "processor_parameters=[Stripe,2],[GoCardless,6]\nbatch_size=100\nmax_retry_count=3",
      'is_active' => 1,
    ],
  ],
];
