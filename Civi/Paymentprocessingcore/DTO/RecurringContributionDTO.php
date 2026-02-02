<?php

namespace Civi\Paymentprocessingcore\DTO;

/**
 * Typed representation of a recurring contribution record.
 *
 * Converts untyped CiviCRM Api4 result arrays into a typed object
 * at the boundary between the API and our service code.
 */
class RecurringContributionDTO {

  /**
   * @var int
   */
  private int $id;

  /**
   * @var int
   */
  private int $contactId;

  /**
   * @var float
   */
  private float $amount;

  /**
   * @var string
   */
  private string $currency;

  /**
   * @var int
   */
  private int $financialTypeId;

  /**
   * @var int|null
   */
  private ?int $campaignId;

  /**
   * @var string
   */
  private string $nextSchedContributionDate;

  /**
   * @var string
   */
  private string $frequencyUnit;

  /**
   * @var int
   */
  private int $frequencyInterval;

  /**
   * Private constructor — use fromApiResult() factory method.
   *
   * @param int $id
   *   Recurring contribution ID.
   * @param int $contactId
   *   Contact ID.
   * @param float $amount
   *   Recurring amount.
   * @param string $currency
   *   Currency code.
   * @param int $financialTypeId
   *   Financial type ID.
   * @param int|null $campaignId
   *   Campaign ID or NULL.
   * @param string $nextSchedContributionDate
   *   Next scheduled contribution date.
   * @param string $frequencyUnit
   *   Frequency unit (day, week, month, year).
   * @param int $frequencyInterval
   *   Frequency interval.
   */
  private function __construct(
    int $id,
    int $contactId,
    float $amount,
    string $currency,
    int $financialTypeId,
    ?int $campaignId,
    string $nextSchedContributionDate,
    string $frequencyUnit,
    int $frequencyInterval
  ) {
    $this->id = $id;
    $this->contactId = $contactId;
    $this->amount = $amount;
    $this->currency = $currency;
    $this->financialTypeId = $financialTypeId;
    $this->campaignId = $campaignId;
    $this->nextSchedContributionDate = $nextSchedContributionDate;
    $this->frequencyUnit = $frequencyUnit;
    $this->frequencyInterval = $frequencyInterval;
  }

  /**
   * Create a DTO from a CiviCRM Api4 result array.
   *
   * @param array<string, mixed> $record
   *   Api4 result row with keys: id, contact_id,
   *   next_sched_contribution_date, amount, currency,
   *   financial_type_id, campaign_id, frequency_unit:name,
   *   frequency_interval.
   *
   * @return self
   *   Typed DTO instance.
   *
   * @throws \InvalidArgumentException
   *   If required fields are missing or invalid.
   */
  public static function fromApiResult(array $record): self {
    if (empty($record['id']) || !is_numeric($record['id'])) {
      throw new \InvalidArgumentException('Recurring contribution record must have a numeric id.');
    }

    if (empty($record['contact_id']) || !is_numeric($record['contact_id'])) {
      throw new \InvalidArgumentException('Recurring contribution record must have a numeric contact_id.');
    }

    if (empty($record['next_sched_contribution_date'])) {
      throw new \InvalidArgumentException('Recurring contribution record must have a next_sched_contribution_date.');
    }

    /** @var int|string|float $id */
    $id = $record['id'];
    /** @var int|string|float $contactId */
    $contactId = $record['contact_id'];
    /** @var int|string|float|null $amount */
    $amount = $record['amount'] ?? 0;
    /** @var string $currency */
    $currency = $record['currency'] ?? '';
    /** @var int|string|float|null $financialTypeId */
    $financialTypeId = $record['financial_type_id'] ?? 0;
    /** @var string $nextSchedDate */
    $nextSchedDate = $record['next_sched_contribution_date'];
    /** @var string $frequencyUnit */
    $frequencyUnit = $record['frequency_unit:name'] ?? 'month';
    /** @var int|string|float|null $frequencyInterval */
    $frequencyInterval = $record['frequency_interval'] ?? 1;

    $campaignId = isset($record['campaign_id']) && is_numeric($record['campaign_id'])
      ? (int) $record['campaign_id']
      : NULL;

    return new self(
      (int) $id,
      (int) $contactId,
      (float) $amount,
      (string) $currency,
      (int) $financialTypeId,
      $campaignId,
      (string) $nextSchedDate,
      (string) $frequencyUnit,
      (int) $frequencyInterval
    );
  }

  /**
   * Get the recurring contribution ID.
   */
  public function getId(): int {
    return $this->id;
  }

  /**
   * Get the contact ID.
   */
  public function getContactId(): int {
    return $this->contactId;
  }

  /**
   * Get the recurring amount.
   */
  public function getAmount(): float {
    return $this->amount;
  }

  /**
   * Get the currency code.
   */
  public function getCurrency(): string {
    return $this->currency;
  }

  /**
   * Get the financial type ID.
   */
  public function getFinancialTypeId(): int {
    return $this->financialTypeId;
  }

  /**
   * Get the campaign ID, or NULL if not set.
   */
  public function getCampaignId(): ?int {
    return $this->campaignId;
  }

  /**
   * Get the next scheduled contribution date.
   */
  public function getNextSchedContributionDate(): string {
    return $this->nextSchedContributionDate;
  }

  /**
   * Get the frequency unit.
   */
  public function getFrequencyUnit(): string {
    return $this->frequencyUnit;
  }

  /**
   * Get the frequency interval.
   */
  public function getFrequencyInterval(): int {
    return $this->frequencyInterval;
  }

}
