<?php

namespace Civi\Paymentprocessingcore\Service;

use Civi\Paymentprocessingcore\Payability\PayabilityProviderInterface;
use Civi\Paymentprocessingcore\Payability\PayabilityResult;

/**
 * Unit tests for PayabilityProviderRegistry.
 *
 * @group headless
 */
class PayabilityProviderRegistryTest extends \BaseHeadlessTest {

  /**
   * The payability provider registry.
   *
   * @var \Civi\Paymentprocessingcore\Service\PayabilityProviderRegistry
   */
  private PayabilityProviderRegistry $registry;

  /**
   * Set up test fixtures.
   */
  public function setUp(): void {
    parent::setUp();
    $this->registry = new PayabilityProviderRegistry();
  }

  /**
   * Test registerProvider() adds provider to registry.
   */
  public function testRegisterProviderAddsProviderToRegistry() {
    $this->registry->registerProvider('GoCardless', 'gocardless.payability_provider');

    $this->assertTrue($this->registry->hasProvider('GoCardless'));
  }

  /**
   * Test registerProvider() allows multiple processors.
   */
  public function testRegisterProviderAllowsMultipleProcessors() {
    $this->registry->registerProvider('GoCardless', 'gocardless.payability_provider');
    $this->registry->registerProvider('Stripe', 'stripe.payability_provider');

    $this->assertTrue($this->registry->hasProvider('GoCardless'));
    $this->assertTrue($this->registry->hasProvider('Stripe'));
  }

  /**
   * Test hasProvider() returns false for unregistered provider.
   */
  public function testHasProviderReturnsFalseForUnregisteredProvider() {
    $this->assertFalse($this->registry->hasProvider('UnknownProcessor'));
  }

  /**
   * Test getProvider() throws exception for unregistered provider.
   */
  public function testGetProviderThrowsExceptionForUnregisteredProvider() {
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage("No payability provider registered for processor type 'GoCardless'");

    $this->registry->getProvider('GoCardless');
  }

  /**
   * Test getProvider() returns provider implementing interface directly.
   *
   * Providers that implement PayabilityProviderInterface are returned as-is
   * (preferred approach, Liskov Substitution Principle).
   */
  public function testGetProviderReturnsInterfaceImplementorDirectly(): void {
    // Create provider that implements the interface
    $mockProvider = new class implements PayabilityProviderInterface {

      /**
       * Get payability for contributions.
       *
       * @phpstan-param array<int> $contributionIds
       * @phpstan-return array<int, PayabilityResult>
       */
      public function getPayabilityForContributions(array $contributionIds): array {
        return [
          1 => PayabilityResult::canPay('Test reason', 'one_off'),
        ];
      }

    };

    \Civi::$statics['test.interface_provider'] = $mockProvider;
    $this->registry->registerProvider('TestProcessor', 'test.interface_provider');

    $provider = $this->registry->getProvider('TestProcessor');

    // Should return the exact same instance (no adapter needed)
    $this->assertInstanceOf(PayabilityProviderInterface::class, $provider);
    $this->assertSame($mockProvider, $provider);

    unset(\Civi::$statics['test.interface_provider']);
  }

  /**
   * Test getProvider() wraps duck-typed provider in adapter.
   *
   * Providers with getPayabilityForContributions() method but no interface
   * implementation are wrapped in an adapter (Adapter Pattern) to satisfy
   * the return type. This supports providers that cannot use `implements`
   * due to autoload.
   */
  public function testGetProviderWrapsDuckTypedProviderInAdapter(): void {
    // Create provider with method but no interface (duck typing)
    $mockProvider = new class {

      /**
       * Get payability for contributions.
       *
       * @phpstan-param array<int> $contributionIds
       * @phpstan-return array<int, PayabilityResult>
       */
      public function getPayabilityForContributions(array $contributionIds): array {
        return [
          1 => PayabilityResult::cannotPay('Duck typed reason', 'subscription'),
        ];
      }

    };

    \Civi::$statics['test.duck_provider'] = $mockProvider;
    $this->registry->registerProvider('TestProcessor', 'test.duck_provider');

    $provider = $this->registry->getProvider('TestProcessor');

    // Should be wrapped in adapter implementing interface
    $this->assertInstanceOf(PayabilityProviderInterface::class, $provider);
    // Adapter should delegate to original provider
    $result = $provider->getPayabilityForContributions([1]);
    $this->assertArrayHasKey(1, $result);
    $this->assertFalse($result[1]->canPayNow);

    unset(\Civi::$statics['test.duck_provider']);
  }

  /**
   * Test getProvider() throws exception if service has no required method.
   */
  public function testGetProviderThrowsExceptionIfServiceHasNoRequiredMethod(): void {
    \Civi::$statics['test.invalid'] = new \stdClass();
    $this->registry->registerProvider('TestProcessor', 'test.invalid');

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage("must implement PayabilityProviderInterface or have a getPayabilityForContributions() method");

    try {
      $this->registry->getProvider('TestProcessor');
    }
    finally {
      unset(\Civi::$statics['test.invalid']);
    }
  }

  /**
   * Test getRegisteredProcessorTypes() returns all registered processors.
   */
  public function testGetRegisteredProcessorTypesReturnsAllProcessors() {
    $this->registry->registerProvider('GoCardless', 'gocardless.provider');
    $this->registry->registerProvider('Stripe', 'stripe.provider');
    $this->registry->registerProvider('Deluxe', 'deluxe.provider');

    $processors = $this->registry->getRegisteredProcessorTypes();

    $this->assertCount(3, $processors);
    $this->assertContains('GoCardless', $processors);
    $this->assertContains('Stripe', $processors);
    $this->assertContains('Deluxe', $processors);
  }

  /**
   * Test getRegisteredProcessorTypes() returns empty array when no providers.
   */
  public function testGetRegisteredProcessorTypesReturnsEmptyArrayWhenNoProviders() {
    $processors = $this->registry->getRegisteredProcessorTypes();

    $this->assertIsArray($processors);
    $this->assertEmpty($processors);
  }

  /**
   * Test getRegisteredProviders() returns full mapping.
   */
  public function testGetRegisteredProvidersReturnsFullMapping() {
    $this->registry->registerProvider('GoCardless', 'gocardless.provider');
    $this->registry->registerProvider('Stripe', 'stripe.provider');

    $providers = $this->registry->getRegisteredProviders();

    $this->assertEquals([
      'GoCardless' => 'gocardless.provider',
      'Stripe' => 'stripe.provider',
    ], $providers);
  }

}
