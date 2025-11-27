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

    // Register WebhookHandlerRegistry (MUST be registered first, used by other services)
    // MUST be shared (singleton) so handler registrations persist across service lookups
    $this->container->setDefinition(
      'paymentprocessingcore.webhook_handler_registry',
      new Definition(\Civi\Paymentprocessingcore\Service\WebhookHandlerRegistry::class)
    )->setShared(TRUE)->setPublic(TRUE);

    // Register WebhookQueueService
    $this->container->setDefinition(
      'paymentprocessingcore.webhook_queue',
      new Definition(\Civi\Paymentprocessingcore\Service\WebhookQueueService::class)
    )->setAutowired(TRUE)->setPublic(TRUE);

    // Register WebhookQueueRunnerService
    $this->container->setDefinition(
      'paymentprocessingcore.webhook_queue_runner',
      new Definition(\Civi\Paymentprocessingcore\Service\WebhookQueueRunnerService::class)
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
    $this->container->setAlias(
      'Civi\Paymentprocessingcore\Service\WebhookHandlerRegistry',
      'paymentprocessingcore.webhook_handler_registry'
    );
    $this->container->setAlias(
      'Civi\Paymentprocessingcore\Service\WebhookQueueService',
      'paymentprocessingcore.webhook_queue'
    );
    $this->container->setAlias(
      'Civi\Paymentprocessingcore\Service\WebhookQueueRunnerService',
      'paymentprocessingcore.webhook_queue_runner'
    );
  }

}
