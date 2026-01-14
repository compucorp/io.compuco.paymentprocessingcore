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
   * @var object
   */
  private object $handler;

  /**
   * Construct adapter with duck-typed handler.
   *
   * @param object $handler
   *   Handler object with handle(int, array): string method.
   */
  public function __construct(object $handler) {
    $this->handler = $handler;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $params Additional parameters.
   */
  public function handle(int $webhookId, array $params): string {
    /** @var callable(int, array<string, mixed>): string $callback */
    $callback = [$this->handler, 'handle'];
    return $callback($webhookId, $params);
  }

}