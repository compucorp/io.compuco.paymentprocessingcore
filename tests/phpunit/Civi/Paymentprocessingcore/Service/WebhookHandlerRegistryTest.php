<?php

namespace Civi\Paymentprocessingcore\Service;

use Civi\Paymentprocessingcore\Webhook\WebhookHandlerInterface;

/**
 * Unit tests for WebhookHandlerRegistry.
 *
 * @group headless
 */
class WebhookHandlerRegistryTest extends \BaseHeadlessTest {

  /**
   * The webhook handler registry.
   *
   * @var \Civi\Paymentprocessingcore\Service\WebhookHandlerRegistry
   */
  private WebhookHandlerRegistry $registry;

  /**
   * Set up test fixtures.
   */
  public function setUp(): void {
    parent::setUp();
    $this->registry = new WebhookHandlerRegistry();
  }

  /**
   * Test registerHandler() adds handler to registry.
   */
  public function testRegisterHandlerAddsHandlerToRegistry() {
    $this->registry->registerHandler('stripe', 'payment.succeeded', 'stripe.handler.payment_success');

    $this->assertTrue($this->registry->hasHandler('stripe', 'payment.succeeded'));
  }

  /**
   * Test registerHandler() allows multiple handlers for same processor.
   */
  public function testRegisterHandlerAllowsMultipleHandlersForSameProcessor() {
    $this->registry->registerHandler('stripe', 'payment.succeeded', 'stripe.handler.payment_success');
    $this->registry->registerHandler('stripe', 'payment.failed', 'stripe.handler.payment_failed');

    $this->assertTrue($this->registry->hasHandler('stripe', 'payment.succeeded'));
    $this->assertTrue($this->registry->hasHandler('stripe', 'payment.failed'));
  }

  /**
   * Test registerHandler() allows multiple processors.
   */
  public function testRegisterHandlerAllowsMultipleProcessors() {
    $this->registry->registerHandler('stripe', 'payment.succeeded', 'stripe.handler.payment_success');
    $this->registry->registerHandler('gocardless', 'payment.succeeded', 'gocardless.handler.payment_success');

    $this->assertTrue($this->registry->hasHandler('stripe', 'payment.succeeded'));
    $this->assertTrue($this->registry->hasHandler('gocardless', 'payment.succeeded'));
  }

  /**
   * Test hasHandler() returns false for unregistered handler.
   */
  public function testHasHandlerReturnsFalseForUnregisteredHandler() {
    $this->assertFalse($this->registry->hasHandler('stripe', 'payment.succeeded'));
  }

  /**
   * Test getHandler() throws exception for unregistered handler.
   */
  public function testGetHandlerThrowsExceptionForUnregisteredHandler() {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage("No webhook handler registered for processor 'stripe' event 'payment.succeeded'");

    $this->registry->getHandler('stripe', 'payment.succeeded');
  }

  /**
   * Test getHandler() returns handler implementing interface directly.
   *
   * Handlers that implement WebhookHandlerInterface are returned as-is
   * (preferred approach, Liskov Substitution Principle).
   */
  public function testGetHandlerReturnsInterfaceImplementorDirectly(): void {
    // Create handler that implements the interface
    $mockHandler = new class implements WebhookHandlerInterface {

      /**
       * Handle webhook event.
       *
       * @phpstan-param array<string, mixed> $params
       */
      public function handle(int $webhookId, array $params): string {
        return 'applied';
      }

    };

    \Civi::$statics['test.interface_handler'] = $mockHandler;
    $this->registry->registerHandler('test', 'payment.succeeded', 'test.interface_handler');

    $handler = $this->registry->getHandler('test', 'payment.succeeded');

    // Should return the exact same instance (no adapter needed)
    $this->assertInstanceOf(WebhookHandlerInterface::class, $handler);
    $this->assertSame($mockHandler, $handler);

    unset(\Civi::$statics['test.interface_handler']);
  }

  /**
   * Test getHandler() wraps duck-typed handler in adapter.
   *
   * Handlers with handle() method but no interface implementation are
   * wrapped in an adapter (Adapter Pattern) to satisfy the return type.
   * This supports handlers that cannot use `implements` due to autoload.
   */
  public function testGetHandlerWrapsDuckTypedHandlerInAdapter(): void {
    // Create handler with handle() method but no interface (duck typing)
    $mockHandler = new class {

      /**
       * Handle webhook event.
       *
       * @phpstan-param array<string, mixed> $params
       */
      public function handle(int $webhookId, array $params): string {
        return 'duck_typed';
      }

    };

    \Civi::$statics['test.duck_handler'] = $mockHandler;
    $this->registry->registerHandler('test', 'payment.succeeded', 'test.duck_handler');

    $handler = $this->registry->getHandler('test', 'payment.succeeded');

    // Should be wrapped in adapter implementing interface
    $this->assertInstanceOf(WebhookHandlerInterface::class, $handler);
    // Adapter should delegate to original handler
    $this->assertEquals('duck_typed', $handler->handle(1, []));

    unset(\Civi::$statics['test.duck_handler']);
  }

  /**
   * Test getHandler() throws exception if service has no handle() method.
   */
  public function testGetHandlerThrowsExceptionIfServiceHasNoHandleMethod(): void {
    \Civi::$statics['test.invalid'] = new \stdClass();
    $this->registry->registerHandler('test', 'payment.succeeded', 'test.invalid');

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage("must implement WebhookHandlerInterface or have a handle() method");

    try {
      $this->registry->getHandler('test', 'payment.succeeded');
    }
    finally {
      unset(\Civi::$statics['test.invalid']);
    }
  }

  /**
   * Test getRegisteredProcessorTypes() returns all registered processors.
   */
  public function testGetRegisteredProcessorTypesReturnsAllProcessors() {
    $this->registry->registerHandler('stripe', 'payment.succeeded', 'stripe.handler');
    $this->registry->registerHandler('gocardless', 'mandate.created', 'gocardless.handler');
    $this->registry->registerHandler('deluxe', 'payment.completed', 'deluxe.handler');

    $processors = $this->registry->getRegisteredProcessorTypes();

    $this->assertCount(3, $processors);
    $this->assertContains('stripe', $processors);
    $this->assertContains('gocardless', $processors);
    $this->assertContains('deluxe', $processors);
  }

  /**
   * Test getRegisteredProcessorTypes() returns empty array when no handlers.
   */
  public function testGetRegisteredProcessorTypesReturnsEmptyArrayWhenNoHandlers() {
    $processors = $this->registry->getRegisteredProcessorTypes();

    $this->assertIsArray($processors);
    $this->assertEmpty($processors);
  }

  /**
   * Test getRegisteredEventTypes() returns event types for a processor.
   */
  public function testGetRegisteredEventTypesReturnsEventTypesForProcessor() {
    $this->registry->registerHandler('stripe', 'payment.succeeded', 'stripe.handler.success');
    $this->registry->registerHandler('stripe', 'payment.failed', 'stripe.handler.failed');
    $this->registry->registerHandler('gocardless', 'mandate.created', 'gocardless.handler');

    $stripeEvents = $this->registry->getRegisteredEventTypes('stripe');

    $this->assertCount(2, $stripeEvents);
    $this->assertContains('payment.succeeded', $stripeEvents);
    $this->assertContains('payment.failed', $stripeEvents);
  }

  /**
   * Test getRegisteredEventTypes() returns empty array for unknown processor.
   */
  public function testGetRegisteredEventTypesReturnsEmptyArrayForUnknownProcessor() {
    $events = $this->registry->getRegisteredEventTypes('unknown');

    $this->assertIsArray($events);
    $this->assertEmpty($events);
  }

}
