<?php

namespace Civi\Paymentprocessingcore\Event;

use Civi\Core\Event\GenericHookEvent;

/**
 * Batch event for charging multiple instalments.
 *
 * Dispatched once per processor type with all contributions to charge.
 * Allows processor extensions to optimize API calls (batching, rate limiting).
 *
 * Payment processor extensions (Stripe, GoCardless, etc.) subscribe to this
 * event and process the items according to their specific API requirements.
 *
 * Example subscriber usage:
 * @code
 * public function onChargeInstalmentBatch(ChargeInstalmentBatchEvent $event): void {
 *   if ($event->getProcessorType() !== 'Stripe') {
 *     return;
 *   }
 *   foreach ($event->getItems() as $item) {
 *     // Process each item...
 *   }
 * }
 * @endcode
 */
class ChargeInstalmentBatchEvent extends GenericHookEvent {

  /**
   * Event name constant.
   */
  public const NAME = 'paymentprocessingcore.charge_instalment_batch';

  /**
   * Constructor.
   *
   * @param string $processorType
   *   Processor type name (e.g., 'Stripe', 'GoCardless').
   * @param array<int, \Civi\Paymentprocessingcore\DTO\ChargeInstalmentItem> $items
   *   Array of items to charge, keyed by contribution ID.
   * @param int $maxRetryCount
   *   Maximum retry count before marking contribution as Failed.
   */
  public function __construct(
    protected string $processorType,
    protected array $items,
    protected int $maxRetryCount = 3,
  ) {}

  /**
   * Get the processor type.
   *
   * @return string
   *   The processor type name.
   */
  public function getProcessorType(): string {
    return $this->processorType;
  }

  /**
   * Get the items to charge.
   *
   * @return array<int, \Civi\Paymentprocessingcore\DTO\ChargeInstalmentItem>
   *   Array of items to charge, keyed by contribution ID.
   */
  public function getItems(): array {
    return $this->items;
  }

  /**
   * Get the max retry count.
   *
   * @return int
   *   Maximum retry count before marking contribution as Failed.
   */
  public function getMaxRetryCount(): int {
    return $this->maxRetryCount;
  }

}
