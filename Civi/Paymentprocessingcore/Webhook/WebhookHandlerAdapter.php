<?php

namespace Civi\Paymentprocessingcore\Webhook;

/**
 * Adapter for duck-typed webhook handlers.
 *
 * Wraps handlers that have a handle() method but don't implement
 * WebhookHandlerInterface. This allows the registry to return a
 * consistent interface while supporting duck typing for handlers
 * that cannot implement the interface due to autoload constraints.
 *
 * @package Civi\Paymentprocessingcore\Webhook
 */
class WebhookHandlerAdapter implements WebhookHandlerInterface {

  /**
   * The wrapped duck-typed handler.
   *
   * @var mixed
   */
  private $handler;

  /**
   * Construct adapter with duck-typed handler.
   *
   * @param mixed $handler
   *   Handler object with handle(int, array): string method.
   */
  public function __construct($handler) {
    $this->handler = $handler;
  }

  /**
   * Handle a webhook event by delegating to wrapped handler.
   *
   * @param int $webhookId
   *   The webhook record ID.
   * @param array $params
   *   Additional parameters including event data.
   *
   * @phpstan-param array<string, mixed> $params
   *
   * @return string
   *   Result code: 'applied', 'noop', or 'ignored_out_of_order'.
   */
  public function handle(int $webhookId, array $params): string {
    /** @var callable $callback */
    $callback = [$this->handler, 'handle'];
    return $callback($webhookId, $params);
  }

}
