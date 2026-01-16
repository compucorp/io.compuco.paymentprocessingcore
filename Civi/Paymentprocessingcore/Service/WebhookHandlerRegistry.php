<?php

namespace Civi\Paymentprocessingcore\Service;

use Civi\Paymentprocessingcore\Webhook\WebhookHandlerAdapter;
use Civi\Paymentprocessingcore\Webhook\WebhookHandlerInterface;

/**
 * Registry for processor-specific webhook event handlers.
 *
 * This service maintains a mapping of processor types and event types
 * to their handler service IDs. Payment processor extensions register
 * their handlers during container compilation via DI addMethodCall().
 *
 * The WebhookQueueRunnerService uses this registry to look up the
 * appropriate handler when processing queued webhook events.
 *
 * @package Civi\Paymentprocessingcore\Service
 */
class WebhookHandlerRegistry {

  /**
   * Registered handlers: [processorType => [eventType => serviceId]].
   *
   * @var array<string, array<string, string>>
   */
  private array $handlers = [];

  /**
   * Register a handler for a specific processor and event type.
   *
   * This method is called during container compilation via addMethodCall()
   * from each payment processor extension's ServiceContainer.
   *
   * @param string $processorType The processor type (e.g., 'stripe', 'gocardless', 'deluxe')
   * @param string $eventType The event type (e.g., 'payment_intent.succeeded', 'payments.confirmed')
   * @param string $serviceId The DI container service ID for the handler
   */
  public function registerHandler(string $processorType, string $eventType, string $serviceId): void {
    $this->handlers[$processorType][$eventType] = $serviceId;
  }

  /**
   * Get the handler for a processor and event type.
   *
   * Retrieves the handler service from the container using the registered
   * service ID. Validation follows the Liskov Substitution Principle:
   *
   * 1. Prefers instanceof WebhookHandlerInterface (proper OOP contract)
   * 2. Falls back to duck typing (method_exists) for handlers that cannot
   *    implement the interface due to extension loading order constraints
   *
   * Note: Interface check happens at RUNTIME (here), not at class definition.
   * This allows proper SOLID compliance while avoiding PHP autoload issues
   * that occur when using `implements` in class declarations.
   *
   * @param string $processorType The processor type
   * @param string $eventType The event type
   *
   * @return \Civi\Paymentprocessingcore\Webhook\WebhookHandlerInterface Handler instance
   *
   * @throws \RuntimeException If no handler is registered or handler is invalid
   */
  public function getHandler(string $processorType, string $eventType): WebhookHandlerInterface {
    if (!isset($this->handlers[$processorType][$eventType])) {
      throw new \RuntimeException(
        sprintf(
          "No webhook handler registered for processor '%s' event '%s'",
          $processorType,
          $eventType
        )
      );
    }

    $serviceId = $this->handlers[$processorType][$eventType];

    // Check if handler is mocked in Civi::$statics (for unit testing)
    if (isset(\Civi::$statics[$serviceId])) {
      $handler = \Civi::$statics[$serviceId];
    }
    else {
      $handler = \Civi::service($serviceId);
    }

    // Runtime validation: check interface implementation (Liskov Substitution)
    // This check happens at runtime when all extensions are loaded,
    // avoiding autoload issues that occur at class definition time.
    if ($handler instanceof WebhookHandlerInterface) {
      return $handler;
    }

    // Fallback: Duck typing for handlers that cannot implement interface
    // due to extension loading order constraints. Still validates contract.
    if (is_object($handler) && method_exists($handler, 'handle')) {
      // Wrap in adapter to satisfy return type (Adapter Pattern)
      return new WebhookHandlerAdapter($handler);
    }

    throw new \RuntimeException(
      sprintf(
        "Handler service '%s' must implement WebhookHandlerInterface or have a handle() method",
        $serviceId
      )
    );
  }

  /**
   * Check if a handler is registered for a processor and event type.
   *
   * @param string $processorType The processor type
   * @param string $eventType The event type
   *
   * @return bool TRUE if a handler is registered, FALSE otherwise
   */
  public function hasHandler(string $processorType, string $eventType): bool {
    return isset($this->handlers[$processorType][$eventType]);
  }

  /**
   * Get all registered processor types.
   *
   * Used by runAllQueues() to automatically process webhooks from
   * all enabled payment processors. When a new processor extension
   * is enabled and registers handlers, it automatically appears in
   * this list without any configuration changes.
   *
   * @return array<string> List of processor types (e.g., ['stripe', 'gocardless', 'deluxe'])
   */
  public function getRegisteredProcessorTypes(): array {
    return array_keys($this->handlers);
  }

  /**
   * Get all event types registered for a processor.
   *
   * @param string $processorType The processor type
   *
   * @return array<int|string> List of event types for this processor
   */
  public function getRegisteredEventTypes(string $processorType): array {
    if (!isset($this->handlers[$processorType])) {
      return [];
    }
    return array_keys($this->handlers[$processorType]);
  }

  /**
   * Get all registered handlers (for debugging/admin purposes).
   *
   * @return array<string, array<string, string>> Full handler mapping
   */
  public function getRegisteredHandlers(): array {
    return $this->handlers;
  }

}
