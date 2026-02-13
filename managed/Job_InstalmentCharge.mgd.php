<?php

/**
 * Managed entity definition for instalment charge scheduled job.
 *
 * This job charges due instalment contributions by selecting eligible
 * Pending/Partially paid contributions, creating PaymentAttempt records,
 * and dispatching events for processor-specific charging.
 *
 * Parameters:
 * - processor_type: (required) Specify which processor type(s) to charge
 *   (e.g., "Stripe" or "Stripe,GoCardless"). Only processors with event
 *   subscribers will actually process charges.
 * - batch_size: Max contributions per processor type (required)
 * - max_retry_count: Skip recurring if failure_count exceeds this (default: 3)
 *
 * @see api/v3/InstalmentCharge/Run.php
 */
return [
  [
    'name' => 'PaymentInstalmentCharge',
    'entity' => 'Job',
    'params' => [
      'version' => 3,
      'name' => 'Charge due instalments for recurring contributions',
      'description' => 'Selects eligible Pending/Partially paid contributions for charging. Creates PaymentAttempt records and dispatches events for processor-specific payment API calls. Processes each processor type sequentially.',
      'run_frequency' => 'Always',
      'api_entity' => 'InstalmentCharge',
      'api_action' => 'Run',
      'api_version' => 3,
      'parameters' => "processor_type=Stripe\nbatch_size=500\nmax_retry_count=3",
      'is_active' => 1,
    ],
  ],
];
