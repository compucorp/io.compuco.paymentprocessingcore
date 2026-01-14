<?php

namespace Civi\Paymentprocessingcore\Webhook;

/**
 * Interface for processor-specific webhook event handlers.
 *
 * This interface defines the contract for webhook handlers following
 * SOLID principles (Interface Segregation, Liskov Substitution).
 *
 * ## Design Pattern: Adapter with Runtime Validation
 *
 * The WebhookHandlerRegistry uses a two-tier validation approach:
 *
 * 1. **Preferred**: Handlers implementing this interface are returned directly
 * 2. **Fallback**: Handlers with `handle()` method are wrapped in an Adapter
 *
 * This allows proper OOP (interface contracts) while supporting handlers that
 * cannot use `implements` due to PHP autoload constraints.
 *
 * ## Why autoload is a problem
 *
 * When CiviCRM loads extension classes (during hook registration, container
 * compilation), PHP autoloads any interfaces in `implements` clauses.
 * If PaymentProcessingCore is not yet loaded, this causes fatal errors.
 *
 * ## Implementation Options
 *
 * **Option 1 (Preferred): Implement interface** - if your extension loads
 * after PaymentProcessingCore:
 * ```php
 * class MyWebhookHandler implements WebhookHandlerInterface {
 *   public function handle(int $webhookId, array $params): string {
 *     return 'applied';
 *   }
 * }
 * ```
 *
 * **Option 2 (Fallback): Duck typing** - if autoload issues occur:
 * ```php
 * class MyWebhookHandler {
 *   public function handle(int $webhookId, array $params): string {
 *     return 'applied';
 *   }
 * }
 * ```
 *
 * Both approaches satisfy the Liskov Substitution Principle - the registry
 * validates the contract at runtime and wraps duck-typed handlers in an
 * Adapter that implements this interface.
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
