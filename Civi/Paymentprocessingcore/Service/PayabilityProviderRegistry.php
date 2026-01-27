<?php

namespace Civi\Paymentprocessingcore\Service;

use Civi\Paymentprocessingcore\Payability\PayabilityProviderAdapter;
use Civi\Paymentprocessingcore\Payability\PayabilityProviderInterface;

/**
 * Registry for processor-specific payability providers.
 *
 * This service maintains a mapping of processor types to their payability
 * provider service IDs. Payment processor extensions register their providers
 * during container compilation via DI addMethodCall().
 *
 * The ContributionPayability API uses this registry to look up the
 * appropriate provider when checking contribution payability.
 *
 * @package Civi\Paymentprocessingcore\Service
 */
class PayabilityProviderRegistry {

  /**
   * Registered providers: [processorType => serviceId].
   *
   * @var array<string, string>
   */
  private array $providers = [];

  /**
   * Register a provider for a specific processor type.
   *
   * This method is called during container compilation via addMethodCall()
   * from each payment processor extension's ServiceContainer.
   *
   * @param string $processorType
   *   The processor type (e.g., 'GoCardless', 'Stripe').
   * @param string $serviceId
   *   The DI container service ID for the provider.
   */
  public function registerProvider(string $processorType, string $serviceId): void {
    $this->providers[$processorType] = $serviceId;
  }

  /**
   * Check if a provider is registered for a processor type.
   *
   * @param string $processorType
   *   The processor type.
   *
   * @return bool
   *   TRUE if a provider is registered, FALSE otherwise.
   */
  public function hasProvider(string $processorType): bool {
    return isset($this->providers[$processorType]);
  }

  /**
   * Get the provider for a processor type.
   *
   * Retrieves the provider service from the container using the registered
   * service ID. Validation follows the Liskov Substitution Principle:
   *
   * 1. Prefers instanceof PayabilityProviderInterface (proper OOP contract)
   * 2. Falls back to duck typing (method_exists) for providers that cannot
   *    implement the interface due to extension loading order constraints
   *
   * @param string $processorType
   *   The processor type.
   *
   * @return PayabilityProviderInterface
   *   Provider instance.
   *
   * @throws \RuntimeException
   *   If no provider is registered or provider is invalid.
   */
  public function getProvider(string $processorType): PayabilityProviderInterface {
    if (!isset($this->providers[$processorType])) {
      throw new \RuntimeException(
        sprintf(
          "No payability provider registered for processor type '%s'",
          $processorType
        )
      );
    }

    $serviceId = $this->providers[$processorType];

    // Check if provider is mocked in Civi::$statics (for unit testing)
    if (isset(\Civi::$statics[$serviceId])) {
      $provider = \Civi::$statics[$serviceId];
    }
    else {
      $provider = \Civi::service($serviceId);
    }

    // Runtime validation: check interface implementation (Liskov Substitution)
    // This check happens at runtime when all extensions are loaded,
    // avoiding autoload issues that occur at class definition time.
    if ($provider instanceof PayabilityProviderInterface) {
      return $provider;
    }

    // Fallback: Duck typing for providers that cannot implement interface
    // due to extension loading order constraints. Still validates contract.
    if (is_object($provider) && method_exists($provider, 'getPayabilityForContributions')) {
      // Wrap in adapter to satisfy return type (Adapter Pattern)
      return new PayabilityProviderAdapter($provider);
    }

    throw new \RuntimeException(
      sprintf(
        "Provider service '%s' must implement PayabilityProviderInterface or have a getPayabilityForContributions() method",
        $serviceId
      )
    );
  }

  /**
   * Get all registered processor types.
   *
   * Used by the ContributionPayability API to determine which processors
   * have registered providers. Contributions for unregistered processors
   * will have payability set to NULL.
   *
   * @return array<string>
   *   List of processor types (e.g., ['GoCardless', 'Stripe']).
   */
  public function getRegisteredProcessorTypes(): array {
    return array_keys($this->providers);
  }

  /**
   * Get all registered providers (for debugging/admin purposes).
   *
   * @return array<string, string>
   *   Full provider mapping [processorType => serviceId].
   */
  public function getRegisteredProviders(): array {
    return $this->providers;
  }

}
