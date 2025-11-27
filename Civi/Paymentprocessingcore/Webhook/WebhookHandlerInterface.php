<?php

namespace Civi\Paymentprocessingcore\Webhook;

/**
 * Interface for processor-specific webhook event handlers.
 *
 * All payment processor extensions must implement this interface
 * for their webhook event handlers. This enables the generic
 * WebhookQueueRunnerService to delegate processing to the
 * appropriate processor-specific handler.
 *
 * @package Civi\Paymentprocessingcore\Webhook
 */
interface WebhookHandlerInterface {

  /**
   * Handle a webhook event.
   *
   * This method is called by the WebhookQueueRunnerService when
   * processing a queued webhook event. The handler should:
   *
   * 1. Load the webhook record to get event details
   * 2. Process the event according to its type
   * 3. Return the appropriate result code
   *
   * @param int $webhookId
   *   ID of the civicrm_payment_webhook record
   * @param array $params
   *   Additional parameters passed when queuing. Typically includes
   *   'event_data' with the parsed event payload from the processor.
   *
   * @return string
   *   Result code: 'applied', 'noop', 'ignored_out_of_order', 'error'
   *
   * @throws \Exception
   *   If processing fails critically.
   */
  public function handle(int $webhookId, array $params): string;

}
