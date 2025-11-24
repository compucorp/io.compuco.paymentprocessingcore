<?php
// This file declares a new entity type. For more details, see "hook_civicrm_entityTypes" at:
// https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
return [
  [
    'name' => 'PaymentAttempt',
    'class' => 'CRM_Paymentprocessingcore_DAO_PaymentAttempt',
    'table' => 'civicrm_payment_attempt',
  ],
];
