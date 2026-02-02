<?php

namespace Civi\Paymentprocessingcore\DTO;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RecurringContributionDTO.
 */
class RecurringContributionDTOTest extends TestCase {

  /**
   * Tests that fromApiResult maps all fields correctly.
   */
  public function testFromApiResultWithValidData(): void {
    $record = [
      'id' => 42,
      'contact_id' => 7,
      'amount' => 99.50,
      'currency' => 'GBP',
      'financial_type_id' => 3,
      'campaign_id' => 5,
      'next_sched_contribution_date' => '2025-07-15',
      'frequency_unit:name' => 'week',
      'frequency_interval' => 2,
    ];

    $dto = RecurringContributionDTO::fromApiResult($record);

    $this->assertSame(42, $dto->getId());
    $this->assertSame(7, $dto->getContactId());
    $this->assertSame(99.50, $dto->getAmount());
    $this->assertSame('GBP', $dto->getCurrency());
    $this->assertSame(3, $dto->getFinancialTypeId());
    $this->assertSame(5, $dto->getCampaignId());
    $this->assertSame('2025-07-15', $dto->getNextSchedContributionDate());
    $this->assertSame('week', $dto->getFrequencyUnit());
    $this->assertSame(2, $dto->getFrequencyInterval());
  }

  /**
   * Tests that null campaign_id returns null.
   */
  public function testFromApiResultWithNullCampaignId(): void {
    $record = [
      'id' => 1,
      'contact_id' => 2,
      'amount' => 10.00,
      'currency' => 'USD',
      'financial_type_id' => 1,
      'campaign_id' => NULL,
      'next_sched_contribution_date' => '2025-07-15',
      'frequency_unit:name' => 'month',
      'frequency_interval' => 1,
    ];

    $dto = RecurringContributionDTO::fromApiResult($record);

    $this->assertNull($dto->getCampaignId());
  }

  /**
   * Tests default frequency values when not provided.
   */
  public function testFromApiResultWithDefaultFrequency(): void {
    $record = [
      'id' => 1,
      'contact_id' => 2,
      'amount' => 10.00,
      'currency' => 'USD',
      'financial_type_id' => 1,
      'next_sched_contribution_date' => '2025-07-15',
    ];

    $dto = RecurringContributionDTO::fromApiResult($record);

    $this->assertSame('month', $dto->getFrequencyUnit());
    $this->assertSame(1, $dto->getFrequencyInterval());
  }

  /**
   * Tests that missing id throws InvalidArgumentException.
   */
  public function testFromApiResultThrowsOnMissingId(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('numeric id');

    RecurringContributionDTO::fromApiResult([
      'contact_id' => 2,
      'next_sched_contribution_date' => '2025-07-15',
    ]);
  }

  /**
   * Tests that missing next_sched_contribution_date throws.
   */
  public function testFromApiResultThrowsOnMissingDate(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('next_sched_contribution_date');

    RecurringContributionDTO::fromApiResult([
      'id' => 1,
      'contact_id' => 2,
    ]);
  }

  /**
   * Tests that missing contact_id throws InvalidArgumentException.
   */
  public function testFromApiResultThrowsOnMissingContactId(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('contact_id');

    RecurringContributionDTO::fromApiResult([
      'id' => 1,
      'next_sched_contribution_date' => '2025-07-15',
    ]);
  }

}
