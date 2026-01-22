<?php

namespace Civi\Paymentprocessingcore\Payability;

/**
 * Adapter for duck-typed payability providers.
 *
 * Wraps providers that have a getPayabilityForContributions() method but don't
 * implement PayabilityProviderInterface. This allows the registry to return a
 * consistent interface while supporting duck typing for providers that cannot
 * implement the interface due to autoload constraints.
 *
 * @package Civi\Paymentprocessingcore\Payability
 */
class PayabilityProviderAdapter implements PayabilityProviderInterface {

  /**
   * The wrapped duck-typed provider.
   *
   * @var mixed
   */
  private $provider;

  /**
   * Construct adapter with duck-typed provider.
   *
   * @param mixed $provider
   *   Provider object with getPayabilityForContributions(array): array method.
   */
  public function __construct($provider) {
    $this->provider = $provider;
  }

  /**
   * Get payability info by delegating to wrapped provider.
   *
   * @param array<int> $contributionIds
   *   Array of contribution IDs to check.
   *
   * @return array<int, PayabilityResult>
   *   Array keyed by contribution ID.
   */
  public function getPayabilityForContributions(array $contributionIds): array {
    /** @var callable $callback */
    $callback = [$this->provider, 'getPayabilityForContributions'];
    return $callback($contributionIds);
  }

}
