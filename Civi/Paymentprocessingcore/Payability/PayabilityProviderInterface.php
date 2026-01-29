<?php

namespace Civi\Paymentprocessingcore\Payability;

/**
 * Interface for processor-specific payability providers.
 *
 * This interface defines the contract for payability providers following
 * SOLID principles (Interface Segregation, Liskov Substitution).
 *
 * ## Design Pattern: Adapter with Runtime Validation
 *
 * The PayabilityProviderRegistry uses a two-tier validation approach:
 *
 * 1. **Preferred**: Providers implementing this interface are returned directly
 * 2. **Fallback**: Providers with `getPayabilityForContributions()` method
 *    are wrapped in an Adapter
 *
 * This allows proper OOP (interface contracts) while supporting providers that
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
 * class MyPayabilityProvider implements PayabilityProviderInterface {
 *   public function getPayabilityForContributions(array $contributionIds): array {
 *     return [...];
 *   }
 * }
 * ```
 *
 * **Option 2 (Fallback): Duck typing** - if autoload issues occur:
 * ```php
 * class MyPayabilityProvider {
 *   public function getPayabilityForContributions(array $contributionIds): array {
 *     return [...];
 *   }
 * }
 * ```
 *
 * Both approaches satisfy the Liskov Substitution Principle - the registry
 * validates the contract at runtime and wraps duck-typed providers in an
 * Adapter that implements this interface.
 *
 * @package Civi\Paymentprocessingcore\Payability
 */
interface PayabilityProviderInterface {

  /**
   * Get payability info for contributions.
   *
   * This method is called by the ContributionPayability API when checking
   * if contributions can be paid now. The provider should:
   *
   * 1. Load the contribution records with related data
   * 2. Determine payability based on processor-specific rules
   * 3. Return PayabilityResult objects for each contribution
   *
   * @param array<int> $contributionIds
   *   Array of contribution IDs to check.
   *
   * @return array<int, PayabilityResult>
   *   Array keyed by contribution ID, containing PayabilityResult objects.
   *   Each result indicates whether the contribution can be paid now,
   *   the reason, payment type, and any processor-specific metadata.
   */
  public function getPayabilityForContributions(array $contributionIds): array;

}
