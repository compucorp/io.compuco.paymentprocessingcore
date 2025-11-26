<?php

namespace Civi\Paymentprocessingcore\Hook\Container;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Service Container for PaymentProcessingCore extension.
 *
 * Registers all services for dependency injection.
 *
 * @package Civi\Paymentprocessingcore\Hook\Container
 */
class ServiceContainer {

  /**
   * @var \Symfony\Component\DependencyInjection\ContainerBuilder
   */
  private $container;

  /**
   * ServiceContainer constructor.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerBuilder $container
   */
  public function __construct(ContainerBuilder $container) {
    $this->container = $container;
  }

  /**
   * Registers services to container.
   */
  public function register(): void {
    // Register ContributionCompletionService
    $this->container->setDefinition(
      'paymentprocessingcore.contribution_completion',
      new Definition(\Civi\Paymentprocessingcore\Service\ContributionCompletionService::class)
    )->setAutowired(TRUE)->setPublic(TRUE);

    // Register PaymentProcessorCustomerService
    $this->container->setDefinition(
      'paymentprocessingcore.payment_processor_customer',
      new Definition(\Civi\Paymentprocessingcore\Service\PaymentProcessorCustomerService::class)
    )->setAutowired(TRUE)->setPublic(TRUE);

    // Set class aliases for autowiring
    $this->container->setAlias(
      'Civi\Paymentprocessingcore\Service\ContributionCompletionService',
      'paymentprocessingcore.contribution_completion'
    );
    $this->container->setAlias(
      'Civi\Paymentprocessingcore\Service\PaymentProcessorCustomerService',
      'paymentprocessingcore.payment_processor_customer'
    );
  }

}
