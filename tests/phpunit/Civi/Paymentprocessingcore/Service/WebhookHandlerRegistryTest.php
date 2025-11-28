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
   * Test getHandler() returns handler instance via service container.
   */
  public function testGetHandlerReturnsHandlerInstanceViaServiceContainer() {
    // Create a mock handler
    $mockHandler = new class implements WebhookHandlerInterface {

      public function handle(int $webhookId, array $params): string {
        return 'applied';
      }

    };

    // Register it in Civi::$statics (simulates service container)
    \Civi::$statics['test.mock_handler'] = $mockHandler;

    // Register handler in registry
    $this->registry->registerHandler('test', 'payment.succeeded', 'test.mock_handler');

    // Get handler
    $handler = $this->registry->getHandler('test', 'payment.succeeded');

    $this->assertInstanceOf(WebhookHandlerInterface::class, $handler);
    $this->assertSame($mockHandler, $handler);

    // Cleanup
    unset(\Civi::$statics['test.mock_handler']);
  }

  /**
   * Test getHandler() throws exception if service doesn't implement interface.
   */
  public function testGetHandlerThrowsExceptionIfServiceDoesNotImplementInterface() {
    // Register a non-handler service
    \Civi::$statics['test.not_a_handler'] = new \stdClass();

    $this->registry->registerHandler('test', 'payment.succeeded', 'test.not_a_handler');

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage("Handler service 'test.not_a_handler' does not implement WebhookHandlerInterface");

    $this->registry->getHandler('test', 'payment.succeeded');

    // Cleanup
    unset(\Civi::$statics['test.not_a_handler']);
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
