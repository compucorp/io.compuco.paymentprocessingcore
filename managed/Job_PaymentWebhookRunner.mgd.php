<?php

/**
 * Managed entity definition for webhook queue processing scheduled job.
 *
 * This job processes queued webhook events from ALL registered payment processors
 * (Stripe, GoCardless, Deluxe, etc.) in a single scheduled run.
 *
 * Auto-Discovery: When a new payment processor extension is enabled and registers
 * handlers via the WebhookHandlerRegistry, it automatically appears in the
 * processing queue without any configuration changes.
 *
 * @see api/v3/PaymentWebhookRunner/Run.php
 */
return [
  [
    'name' => 'Job:PaymentWebhookRunner',
    'entity' => 'Job',
    'params' => [
      'version' => 3,
      'name' => 'Process Payment Webhooks',
      'description' => 'Process queued webhook events from all registered payment processors (Stripe, GoCardless, Deluxe, etc.). Automatically processes webhooks from all enabled processors with retry logic and exponential backoff.',
      'run_frequency' => 'Always',
      'api_entity' => 'PaymentWebhookRunner',
      'api_action' => 'Run',
      'parameters' => 'processor_type=all&batch_size=250',
      'is_active' => 1,
    ],
  ],
];
