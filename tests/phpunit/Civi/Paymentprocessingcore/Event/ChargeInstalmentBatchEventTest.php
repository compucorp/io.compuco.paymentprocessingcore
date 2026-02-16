<?php

namespace Civi\Paymentprocessingcore\Event;

use Civi\Paymentprocessingcore\DTO\ChargeInstalmentItem;

/**
 * Tests for ChargeInstalmentBatchEvent.
 *
 * @group headless
 */
class ChargeInstalmentBatchEventTest extends \BaseHeadlessTest {

  /**
   * Tests constructor sets processor type and items.
   */
  public function testConstructorSetsProcessorTypeAndItems(): void {
    $items = [
      1 => new ChargeInstalmentItem(1, 10, 100, 1000, 50.00, 'GBP', 5, 6),
      2 => new ChargeInstalmentItem(2, 20, 200, 2000, 75.00, 'USD', 7, 8),
    ];

    $event = new ChargeInstalmentBatchEvent('Stripe', $items);

    $this->assertEquals('Stripe', $event->getProcessorType());
    $this->assertCount(2, $event->getItems());
  }

  /**
   * Tests getProcessorType returns correct value.
   */
  public function testGetProcessorTypeReturnsCorrectValue(): void {
    $event = new ChargeInstalmentBatchEvent('GoCardless', []);

    $this->assertEquals('GoCardless', $event->getProcessorType());
  }

  /**
   * Tests getItems returns all items.
   */
  public function testGetItemsReturnsAllItems(): void {
    $item1 = new ChargeInstalmentItem(1, 10, 100, 1000, 50.00, 'GBP', 5, 6);
    $item2 = new ChargeInstalmentItem(2, 20, 200, 2000, 75.00, 'USD', 7, 8);
    $item3 = new ChargeInstalmentItem(3, 30, 300, 3000, 100.00, 'EUR', 9, 10);

    $items = [1 => $item1, 2 => $item2, 3 => $item3];

    $event = new ChargeInstalmentBatchEvent('Stripe', $items);

    $returnedItems = $event->getItems();
    $this->assertCount(3, $returnedItems);
    $this->assertArrayHasKey(1, $returnedItems);
    $this->assertArrayHasKey(2, $returnedItems);
    $this->assertArrayHasKey(3, $returnedItems);
    $this->assertSame($item1, $returnedItems[1]);
    $this->assertSame($item2, $returnedItems[2]);
    $this->assertSame($item3, $returnedItems[3]);
  }

  /**
   * Tests event name constant.
   */
  public function testEventNameConstant(): void {
    $this->assertEquals(
      'paymentprocessingcore.charge_instalment_batch',
      ChargeInstalmentBatchEvent::NAME
    );
  }

  /**
   * Tests empty items array is handled.
   */
  public function testEmptyItemsArray(): void {
    $event = new ChargeInstalmentBatchEvent('Stripe', []);

    $this->assertEquals('Stripe', $event->getProcessorType());
    $this->assertCount(0, $event->getItems());
    $this->assertEmpty($event->getItems());
  }

  /**
   * Tests getMaxRetryCount returns default value.
   */
  public function testGetMaxRetryCountReturnsDefaultValue(): void {
    $event = new ChargeInstalmentBatchEvent('Stripe', []);

    $this->assertEquals(3, $event->getMaxRetryCount());
  }

  /**
   * Tests getMaxRetryCount returns custom value.
   */
  public function testGetMaxRetryCountReturnsCustomValue(): void {
    $event = new ChargeInstalmentBatchEvent('Stripe', [], 5);

    $this->assertEquals(5, $event->getMaxRetryCount());
  }

}
