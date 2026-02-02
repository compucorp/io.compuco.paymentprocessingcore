<?php

/**
 * Managed entity definition for instalment generation scheduled job.
 *
 * This job creates Pending contribution records for due recurring
 * contributions (non-membership). No payment processor calls are made.
 *
 * @see api/v3/InstalmentGenerator/Run.php
 */
return [
  [
    'name' => 'Job:InstalmentGenerator',
    'entity' => 'Job',
    'params' => [
      'version' => 3,
      'name' => 'Generate instalments for recurring contributions (non-membership)',
      'description' => 'Creates Pending contribution records for each due In Progress recurring contribution that is not linked to a membership. No payment processor calls are made.',
      'run_frequency' => 'Always',
      'api_entity' => 'InstalmentGenerator',
      'api_action' => 'Run',
      'api_version' => 3,
      'parameters' => "processor_type=Stripe\nbatch_size=500",
      'is_active' => 1,
    ],
  ],
];
