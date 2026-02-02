<?php

use Civi\Paymentprocessingcore\Service\InstalmentGenerationService;

/**
 * Tests for InstalmentGenerationService.
 *
 * Uses the built-in CiviCRM Dummy payment processor type for testing,
 * passing 'Dummy' as the processor_type parameter. The service and
 * scheduled job default to 'Stripe' in production, but the query is
 * fully parameterized to support any payment processor type.
 *
 * @group headless
 */
class Civi_Paymentprocessingcore_Service_InstalmentGenerationServiceTest extends BaseHeadlessTest {

  private const PROCESSOR_TYPE = 'Dummy';

  /**
   * @var \Civi\Paymentprocessingcore\Service\InstalmentGenerationService
   */
  private InstalmentGenerationService $service;

  /**
   * Set up test fixtures.
   */
  public function setUp(): void {
    parent::setUp();
    $this->service = new InstalmentGenerationService();
  }

  /**
   * Tests happy path: generates instalment for due recurring contribution.
   */
  public function testGeneratesInstalmentForDueRecurring(): void {
    $processorId = $this->createPaymentProcessor();
    $contactId = $this->createContact();
    $recurId = $this->createRecurringContribution($contactId, $processorId, [
      'contribution_status_id:name' => 'In Progress',
      'next_sched_contribution_date' => date('Y-m-d', strtotime('-1 day')),
    ]);

    $result = $this->service->generateInstalments(self::PROCESSOR_TYPE, 500);

    $this->assertEquals(1, $result['created']);
    $this->assertEquals(0, $result['skipped']);
    $this->assertEquals(0, $result['errored']);

    // Verify the new contribution exists with correct fields from recurring.
    $contributions = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('contribution_status_id:name', 'contact_id', 'total_amount', 'currency', 'contribution_recur_id', 'is_pay_later')
      ->addWhere('contribution_recur_id', '=', $recurId)
      ->addWhere('contribution_status_id:name', '=', 'Pending')
      ->execute();

    $this->assertCount(1, $contributions);
    $newContribution = $contributions->first();
    $this->assertEquals($contactId, $newContribution['contact_id']);
    $this->assertEquals(50.00, (float) $newContribution['total_amount']);
    $this->assertEquals('GBP', $newContribution['currency']);
    $this->assertEquals($recurId, $newContribution['contribution_recur_id']);
    $this->assertEquals(0, (int) $newContribution['is_pay_later']);

    // Verify schedule date was advanced.
    $recur = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addSelect('next_sched_contribution_date')
      ->addWhere('id', '=', $recurId)
      ->execute()
      ->first();

    $this->assertNotNull($recur);
    $expectedDate = date('Y-m-d', strtotime('+1 month -1 day'));
    $this->assertEquals($expectedDate, date('Y-m-d', strtotime($recur['next_sched_contribution_date'])));
  }

  /**
   * Tests that membership-linked recurring contributions are skipped.
   */
  public function testSkipsMembershipLinkedRecurring(): void {
    $processorId = $this->createPaymentProcessor();
    $contactId = $this->createContact();
    $recurId = $this->createRecurringContribution($contactId, $processorId, [
      'contribution_status_id:name' => 'In Progress',
      'next_sched_contribution_date' => date('Y-m-d', strtotime('-1 day')),
    ]);

    // Link to membership.
    $this->createMembership($contactId, $recurId);

    $result = $this->service->generateInstalments(self::PROCESSOR_TYPE, 500);

    // Membership-linked recurrings are excluded at the DB query level,
    // so they don't appear in any counter.
    $this->assertEquals(0, $result['created']);
    $this->assertEquals(0, $result['skipped']);
    $this->assertEquals(0, $result['errored']);
  }

  /**
   * Tests idempotency: skips when Pending contribution already exists.
   */
  public function testIdempotencySkipsDuplicate(): void {
    $processorId = $this->createPaymentProcessor();
    $contactId = $this->createContact();
    $schedDate = date('Y-m-d', strtotime('-1 day'));
    $recurId = $this->createRecurringContribution($contactId, $processorId, [
      'contribution_status_id:name' => 'In Progress',
      'next_sched_contribution_date' => $schedDate,
    ]);

    // Create existing Pending contribution with same date.
    $this->createContribution($contactId, $recurId, [
      'contribution_status_id:name' => 'Pending',
      'receive_date' => $schedDate,
    ]);

    $result = $this->service->generateInstalments(self::PROCESSOR_TYPE, 500);

    $this->assertEquals(0, $result['created']);
    $this->assertEquals(1, $result['skipped']);
  }

  /**
   * Tests that non-matching processor type is not processed.
   */
  public function testSkipsNonMatchingProcessorType(): void {
    $processorId = $this->createPaymentProcessor();
    $contactId = $this->createContact();
    $this->createRecurringContribution($contactId, $processorId, [
      'contribution_status_id:name' => 'In Progress',
      'next_sched_contribution_date' => date('Y-m-d', strtotime('-1 day')),
    ]);

    // Query with a processor type that doesn't match Dummy.
    $result = $this->service->generateInstalments('NonExistentProcessor', 500);

    $this->assertEquals(0, $result['created']);
    $this->assertEquals(0, $result['skipped']);
  }

  /**
   * Tests that future scheduled dates are not processed.
   */
  public function testSkipsFutureScheduledDate(): void {
    $processorId = $this->createPaymentProcessor();
    $contactId = $this->createContact();
    $this->createRecurringContribution($contactId, $processorId, [
      'contribution_status_id:name' => 'In Progress',
      'next_sched_contribution_date' => date('Y-m-d', strtotime('+7 days')),
    ]);

    $result = $this->service->generateInstalments(self::PROCESSOR_TYPE, 500);

    $this->assertEquals(0, $result['created']);
    $this->assertEquals(0, $result['skipped']);
  }

  /**
   * Tests that non-In Progress statuses are not processed.
   */
  public function testSkipsNonInProgressStatus(): void {
    $processorId = $this->createPaymentProcessor();
    $contactId = $this->createContact();

    foreach (['Cancelled', 'Pending', 'Failed'] as $status) {
      $this->createRecurringContribution($contactId, $processorId, [
        'contribution_status_id:name' => $status,
        'next_sched_contribution_date' => date('Y-m-d', strtotime('-1 day')),
      ]);
    }

    $result = $this->service->generateInstalments(self::PROCESSOR_TYPE, 500);

    $this->assertEquals(0, $result['created']);
  }

  /**
   * Tests that schedule date advances correctly for different intervals.
   */
  public function testAdvancesScheduleDateCorrectly(): void {
    $processorId = $this->createPaymentProcessor();
    $contactId = $this->createContact();
    $baseDate = '2025-06-15';

    $cases = [
      ['unit' => 'month', 'interval' => 1, 'expected' => '2025-07-15'],
      ['unit' => 'week', 'interval' => 2, 'expected' => '2025-06-29'],
      ['unit' => 'year', 'interval' => 1, 'expected' => '2026-06-15'],
      ['unit' => 'day', 'interval' => 30, 'expected' => '2025-07-15'],
    ];

    foreach ($cases as $case) {
      $recurId = $this->createRecurringContribution($contactId, $processorId, [
        'contribution_status_id:name' => 'In Progress',
        'next_sched_contribution_date' => $baseDate,
        'frequency_unit:name' => $case['unit'],
        'frequency_interval' => $case['interval'],
      ]);

      $this->service->advanceScheduleDate(
        $recurId,
        $baseDate,
        $case['unit'],
        $case['interval']
      );

      $recur = \Civi\Api4\ContributionRecur::get(FALSE)
        ->addSelect('next_sched_contribution_date')
        ->addWhere('id', '=', $recurId)
        ->execute()
        ->first();

      $this->assertNotNull($recur);
      $this->assertEquals(
        $case['expected'],
        date('Y-m-d', strtotime($recur['next_sched_contribution_date'])),
        sprintf('Failed for unit=%s interval=%d', $case['unit'], $case['interval'])
      );
    }
  }

  /**
   * Tests that batch size limits the number of records processed.
   */
  public function testBatchSizeLimitsProcessing(): void {
    $processorId = $this->createPaymentProcessor();
    $contactId = $this->createContact();

    for ($i = 0; $i < 3; $i++) {
      $this->createRecurringContribution($contactId, $processorId, [
        'contribution_status_id:name' => 'In Progress',
        'next_sched_contribution_date' => date('Y-m-d', strtotime('-' . ($i + 1) . ' days')),
      ]);
    }

    $result = $this->service->generateInstalments(self::PROCESSOR_TYPE, 2);

    $this->assertLessThanOrEqual(2, $result['created']);
  }

  /**
   * Tests that one failure does not stop the batch.
   */
  public function testContinuesOnSingleRecordError(): void {
    $processorId = $this->createPaymentProcessor();
    $contactId = $this->createContact();

    // Create a valid recurring contribution.
    $recurId1 = $this->createRecurringContribution($contactId, $processorId, [
      'contribution_status_id:name' => 'In Progress',
      'next_sched_contribution_date' => date('Y-m-d', strtotime('-1 day')),
    ]);

    // Create a recurring contribution without financial_type_id (will cause error).
    $recurId2 = $this->createRecurringContribution($contactId, $processorId, [
      'contribution_status_id:name' => 'In Progress',
      'next_sched_contribution_date' => date('Y-m-d', strtotime('-2 days')),
    ]);

    // Remove financial_type_id to trigger error during contribution creation.
    \CRM_Core_DAO::executeQuery(
      'UPDATE civicrm_contribution_recur SET financial_type_id = NULL WHERE id = %1',
      [1 => [$recurId2, 'Integer']]
    );

    $result = $this->service->generateInstalments(self::PROCESSOR_TYPE, 500);

    // At least one should succeed or error - batch continues.
    $totalProcessed = $result['created'] + $result['errored'];
    $this->assertGreaterThan(0, $totalProcessed);
    $this->assertGreaterThan(0, $result['errored']);
    $this->assertEmpty($result['errors'][$recurId1] ?? NULL);
  }

  /**
   * Tests that Failed contribution also blocks re-creation (idempotency).
   */
  public function testIdempotencyChecksAllStatuses(): void {
    $processorId = $this->createPaymentProcessor();
    $contactId = $this->createContact();
    $schedDate = date('Y-m-d', strtotime('-1 day'));

    // Test instalmentExists directly for each blocking status.
    $statuses = ['Pending', 'Completed', 'Failed', 'Cancelled'];
    foreach ($statuses as $status) {
      $recurId = $this->createRecurringContribution($contactId, $processorId, [
        'contribution_status_id:name' => 'In Progress',
        'next_sched_contribution_date' => $schedDate,
      ]);

      // Create contribution with the blocking status.
      $this->createContribution($contactId, $recurId, [
        'contribution_status_id:name' => $status,
        'receive_date' => $schedDate,
      ]);

      $this->assertTrue(
        $this->service->instalmentExists($recurId, $schedDate),
        "Expected instalmentExists to return TRUE for status: $status"
      );
    }
  }

  /**
   * Tests getDueRecurringContributions returns correct fields.
   */
  public function testGetDueRecurringContributionsReturnsCorrectFields(): void {
    $processorId = $this->createPaymentProcessor();
    $contactId = $this->createContact();
    $this->createRecurringContribution($contactId, $processorId, [
      'contribution_status_id:name' => 'In Progress',
      'next_sched_contribution_date' => date('Y-m-d', strtotime('-1 day')),
      'frequency_unit:name' => 'week',
      'frequency_interval' => 2,
    ]);

    $results = $this->service->getDueRecurringContributions(self::PROCESSOR_TYPE, 500);

    $this->assertCount(1, $results);
    $record = $results[0];
    $this->assertArrayHasKey('id', $record);
    $this->assertArrayHasKey('next_sched_contribution_date', $record);
    $this->assertArrayHasKey('frequency_unit:name', $record);
    $this->assertArrayHasKey('frequency_interval', $record);
    $this->assertArrayHasKey('contact_id', $record);
    $this->assertArrayHasKey('amount', $record);
    $this->assertArrayHasKey('currency', $record);
    $this->assertArrayHasKey('financial_type_id', $record);
    $this->assertEquals('week', $record['frequency_unit:name']);
    $this->assertEquals(2, $record['frequency_interval']);
  }

  /**
   * Tests getDueRecurringContributions excludes membership-linked records.
   */
  public function testGetDueRecurringContributionsExcludesMembership(): void {
    $processorId = $this->createPaymentProcessor();
    $contactId = $this->createContact();

    // Non-membership recur (should be returned).
    $this->createRecurringContribution($contactId, $processorId, [
      'contribution_status_id:name' => 'In Progress',
      'next_sched_contribution_date' => date('Y-m-d', strtotime('-1 day')),
    ]);

    // Membership-linked recur (should be excluded).
    $membershipRecurId = $this->createRecurringContribution($contactId, $processorId, [
      'contribution_status_id:name' => 'In Progress',
      'next_sched_contribution_date' => date('Y-m-d', strtotime('-1 day')),
    ]);
    $this->createMembership($contactId, $membershipRecurId);

    $results = $this->service->getDueRecurringContributions(self::PROCESSOR_TYPE, 500);

    $this->assertCount(1, $results);
  }

  /**
   * Tests getDueRecurringContributions respects batch size.
   */
  public function testGetDueRecurringContributionsRespectsBatchSize(): void {
    $processorId = $this->createPaymentProcessor();
    $contactId = $this->createContact();

    for ($i = 0; $i < 5; $i++) {
      $this->createRecurringContribution($contactId, $processorId, [
        'contribution_status_id:name' => 'In Progress',
        'next_sched_contribution_date' => date('Y-m-d', strtotime('-' . ($i + 1) . ' days')),
      ]);
    }

    $results = $this->service->getDueRecurringContributions(self::PROCESSOR_TYPE, 3);

    $this->assertCount(3, $results);
  }

  /**
   * Tests getDueRecurringContributions filters by processor type.
   */
  public function testGetDueRecurringContributionsFiltersByProcessorType(): void {
    $processorId = $this->createPaymentProcessor();
    $contactId = $this->createContact();
    $this->createRecurringContribution($contactId, $processorId, [
      'contribution_status_id:name' => 'In Progress',
      'next_sched_contribution_date' => date('Y-m-d', strtotime('-1 day')),
    ]);

    $results = $this->service->getDueRecurringContributions('NonExistent', 500);

    $this->assertCount(0, $results);
  }

  /**
   * Tests getDueRecurringContributions only returns In Progress status.
   */
  public function testGetDueRecurringContributionsFiltersStatus(): void {
    $processorId = $this->createPaymentProcessor();
    $contactId = $this->createContact();

    $this->createRecurringContribution($contactId, $processorId, [
      'contribution_status_id:name' => 'In Progress',
      'next_sched_contribution_date' => date('Y-m-d', strtotime('-1 day')),
    ]);
    $this->createRecurringContribution($contactId, $processorId, [
      'contribution_status_id:name' => 'Cancelled',
      'next_sched_contribution_date' => date('Y-m-d', strtotime('-1 day')),
    ]);
    $this->createRecurringContribution($contactId, $processorId, [
      'contribution_status_id:name' => 'Pending',
      'next_sched_contribution_date' => date('Y-m-d', strtotime('-1 day')),
    ]);

    $results = $this->service->getDueRecurringContributions(self::PROCESSOR_TYPE, 500);

    $this->assertCount(1, $results);
  }

  /**
   * Tests getDueRecurringContributions excludes future dates.
   */
  public function testGetDueRecurringContributionsExcludesFutureDates(): void {
    $processorId = $this->createPaymentProcessor();
    $contactId = $this->createContact();

    $this->createRecurringContribution($contactId, $processorId, [
      'contribution_status_id:name' => 'In Progress',
      'next_sched_contribution_date' => date('Y-m-d', strtotime('-1 day')),
    ]);
    $this->createRecurringContribution($contactId, $processorId, [
      'contribution_status_id:name' => 'In Progress',
      'next_sched_contribution_date' => date('Y-m-d', strtotime('+7 days')),
    ]);

    $results = $this->service->getDueRecurringContributions(self::PROCESSOR_TYPE, 500);

    $this->assertCount(1, $results);
  }

  /**
   * Tests getDueRecurringContributions returns empty array when no matches.
   */
  public function testGetDueRecurringContributionsReturnsEmptyWhenNoMatches(): void {
    $results = $this->service->getDueRecurringContributions(self::PROCESSOR_TYPE, 500);

    $this->assertCount(0, $results);
  }

  /**
   * Tests instalmentExists returns FALSE when no contribution exists.
   */
  public function testInstalmentExistsReturnsFalseWhenNone(): void {
    $processorId = $this->createPaymentProcessor();
    $contactId = $this->createContact();
    $recurId = $this->createRecurringContribution($contactId, $processorId);

    $this->assertFalse($this->service->instalmentExists($recurId, date('Y-m-d')));
  }

  /**
   * Tests instalmentExists returns FALSE for different date.
   */
  public function testInstalmentExistsReturnsFalseForDifferentDate(): void {
    $processorId = $this->createPaymentProcessor();
    $contactId = $this->createContact();
    $recurId = $this->createRecurringContribution($contactId, $processorId);

    $this->createContribution($contactId, $recurId, [
      'contribution_status_id:name' => 'Pending',
      'receive_date' => '2025-06-15',
    ]);

    // Check a different date.
    $this->assertFalse($this->service->instalmentExists($recurId, '2025-07-15'));
  }

  /**
   * Tests instalmentExists returns FALSE for different recur ID.
   */
  public function testInstalmentExistsReturnsFalseForDifferentRecur(): void {
    $processorId = $this->createPaymentProcessor();
    $contactId = $this->createContact();
    $recurId1 = $this->createRecurringContribution($contactId, $processorId);
    $recurId2 = $this->createRecurringContribution($contactId, $processorId);

    $date = date('Y-m-d', strtotime('-1 day'));
    $this->createContribution($contactId, $recurId1, [
      'contribution_status_id:name' => 'Pending',
      'receive_date' => $date,
    ]);

    // Different recur ID should not match.
    $this->assertFalse($this->service->instalmentExists($recurId2, $date));
  }

  /**
   * Tests instalmentExists matches datetime receive_date against date-only input.
   */
  public function testInstalmentExistsHandlesDatetimeVsDate(): void {
    $processorId = $this->createPaymentProcessor();
    $contactId = $this->createContact();
    $recurId = $this->createRecurringContribution($contactId, $processorId);

    // Contribution stored with full datetime.
    $this->createContribution($contactId, $recurId, [
      'contribution_status_id:name' => 'Pending',
      'receive_date' => '2025-06-15 14:30:00',
    ]);

    // Check with date-only string should still match.
    $this->assertTrue($this->service->instalmentExists($recurId, '2025-06-15'));
  }

  /**
   * Tests createInstalment returns a contribution ID.
   */
  public function testCreateInstalmentReturnsContributionId(): void {
    $processorId = $this->createPaymentProcessor();
    $contactId = $this->createContact();
    $recurId = $this->createRecurringContribution($contactId, $processorId);

    $recur = $this->getRecurRecord($recurId);
    $receiveDate = date('Y-m-d');
    $contributionId = $this->service->createInstalment($recur, $receiveDate);

    $this->assertGreaterThan(0, $contributionId);
  }

  /**
   * Tests createInstalment creates contribution with Pending status.
   */
  public function testCreateInstalmentCreatesPendingContribution(): void {
    $processorId = $this->createPaymentProcessor();
    $contactId = $this->createContact();
    $recurId = $this->createRecurringContribution($contactId, $processorId);

    $recur = $this->getRecurRecord($recurId);
    $receiveDate = date('Y-m-d');
    $contributionId = $this->service->createInstalment($recur, $receiveDate);

    $contribution = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('contribution_status_id:name', 'contribution_recur_id')
      ->addWhere('id', '=', $contributionId)
      ->execute()
      ->first();

    $this->assertNotNull($contribution);
    $this->assertEquals('Pending', $contribution['contribution_status_id:name']);
    $this->assertEquals($recurId, $contribution['contribution_recur_id']);
  }

  /**
   * Tests createInstalment copies campaign_id when present in recur record.
   */
  public function testCreateInstalmentCopiesCampaignId(): void {
    $processorId = $this->createPaymentProcessor();
    $contactId = $this->createContact();

    /** @var array{id: int, values: array<int, array{id: int}>} $campaignResult */
    $campaignResult = civicrm_api3('Campaign', 'create', [
      'title' => 'Test Campaign',
      'name' => 'test_campaign',
    ]);
    $campaignId = (int) $campaignResult['values'][$campaignResult['id']]['id'];

    $recurId = $this->createRecurringContribution($contactId, $processorId);
    $recur = $this->getRecurRecord($recurId);
    $recur['campaign_id'] = $campaignId;

    $contributionId = $this->service->createInstalment($recur, date('Y-m-d'));

    $result = \CRM_Core_DAO::singleValueQuery(
      'SELECT campaign_id FROM civicrm_contribution WHERE id = %1',
      [1 => [$contributionId, 'Integer']]
    );

    $this->assertEquals($campaignId, (int) $result);
  }

  /**
   * Tests createInstalment sets is_pay_later to 0.
   */
  public function testCreateInstalmentSetsIsPayLaterToZero(): void {
    $processorId = $this->createPaymentProcessor();
    $contactId = $this->createContact();
    $recurId = $this->createRecurringContribution($contactId, $processorId);

    $recur = $this->getRecurRecord($recurId);
    $contributionId = $this->service->createInstalment($recur, date('Y-m-d'));

    $contribution = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('is_pay_later')
      ->addWhere('id', '=', $contributionId)
      ->execute()
      ->first();

    $this->assertNotNull($contribution);
    $this->assertEquals(0, (int) $contribution['is_pay_later']);
  }

  /**
   * Tests end-of-month handling for monthly schedule advancement.
   */
  public function testAdvanceScheduleDateHandlesEndOfMonth(): void {
    $processorId = $this->createPaymentProcessor();
    $contactId = $this->createContact();

    $cases = [
      ['from' => '2025-01-31', 'expected' => '2025-02-28'],
      ['from' => '2025-02-28', 'expected' => '2025-03-28'],
      ['from' => '2025-03-31', 'expected' => '2025-04-30'],
      ['from' => '2024-01-31', 'expected' => '2024-02-29'],
      ['from' => '2025-01-30', 'expected' => '2025-02-28'],
      ['from' => '2025-05-31', 'expected' => '2025-06-30'],
    ];

    foreach ($cases as $case) {
      $recurId = $this->createRecurringContribution($contactId, $processorId, [
        'next_sched_contribution_date' => $case['from'],
      ]);

      $this->service->advanceScheduleDate($recurId, $case['from'], 'month', 1);

      $recur = \Civi\Api4\ContributionRecur::get(FALSE)
        ->addSelect('next_sched_contribution_date')
        ->addWhere('id', '=', $recurId)
        ->execute()
        ->first();

      $this->assertNotNull($recur);
      $this->assertEquals(
        $case['expected'],
        date('Y-m-d', strtotime($recur['next_sched_contribution_date'])),
        sprintf('Failed for %s + 1 month', $case['from'])
      );
    }
  }

  /**
   * Tests advanceScheduleDate updates the database record.
   */
  public function testAdvanceScheduleDateUpdatesRecord(): void {
    $processorId = $this->createPaymentProcessor();
    $contactId = $this->createContact();
    $recurId = $this->createRecurringContribution($contactId, $processorId, [
      'next_sched_contribution_date' => '2025-03-01',
    ]);

    $this->service->advanceScheduleDate($recurId, '2025-03-01', 'month', 1);

    $recur = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addSelect('next_sched_contribution_date')
      ->addWhere('id', '=', $recurId)
      ->execute()
      ->first();

    $this->assertNotNull($recur);
    $this->assertEquals('2025-04-01', date('Y-m-d', strtotime($recur['next_sched_contribution_date'])));
  }

  /**
   * Tests advanceScheduleDate does not affect other recurring records.
   */
  public function testAdvanceScheduleDateDoesNotAffectOtherRecords(): void {
    $processorId = $this->createPaymentProcessor();
    $contactId = $this->createContact();
    $recurId1 = $this->createRecurringContribution($contactId, $processorId, [
      'next_sched_contribution_date' => '2025-03-01',
    ]);
    $recurId2 = $this->createRecurringContribution($contactId, $processorId, [
      'next_sched_contribution_date' => '2025-03-01',
    ]);

    $this->service->advanceScheduleDate($recurId1, '2025-03-01', 'month', 1);

    $recur2 = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addSelect('next_sched_contribution_date')
      ->addWhere('id', '=', $recurId2)
      ->execute()
      ->first();

    $this->assertNotNull($recur2);
    $this->assertEquals('2025-03-01', date('Y-m-d', strtotime($recur2['next_sched_contribution_date'])));
  }

  /**
   * Tests injectable reference date controls which contributions are due.
   */
  public function testInjectableReferenceDateControlsDueContributions(): void {
    $processorId = $this->createPaymentProcessor();
    $contactId = $this->createContact();
    $this->createRecurringContribution($contactId, $processorId, [
      'contribution_status_id:name' => 'In Progress',
      'next_sched_contribution_date' => '2025-06-15',
    ]);

    // Reference date before the scheduled date — should not pick it up.
    $result = $this->service->generateInstalments(self::PROCESSOR_TYPE, 500, '2025-06-14');
    $this->assertEquals(0, $result['created']);

    // Reference date on the scheduled date — should pick it up.
    $result = $this->service->generateInstalments(self::PROCESSOR_TYPE, 500, '2025-06-15');
    $this->assertEquals(1, $result['created']);
  }

  /**
   * Get a recurring contribution record with fields needed by createInstalment.
   *
   * @param int $recurId
   *   The recurring contribution ID.
   *
   * @return array<string, mixed>
   *   The recurring contribution record.
   */
  private function getRecurRecord(int $recurId): array {
    $recur = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addSelect('id', 'contact_id', 'amount', 'currency', 'financial_type_id', 'campaign_id')
      ->addWhere('id', '=', $recurId)
      ->execute()
      ->first();

    if (!is_array($recur)) {
      throw new \RuntimeException('Failed to get ContributionRecur ' . $recurId);
    }

    return $recur;
  }

  /**
   * Create a payment processor using the built-in Dummy type.
   *
   * @return int
   *   The payment processor ID.
   */
  private function createPaymentProcessor(): int {
    $processor = \Civi\Api4\PaymentProcessor::create(FALSE)
      ->addValue('name', 'Test Processor')
      ->addValue('payment_processor_type_id:name', self::PROCESSOR_TYPE)
      ->addValue('class_name', 'Payment_Dummy')
      ->addValue('is_active', TRUE)
      ->addValue('is_test', FALSE)
      ->addValue('domain_id', 1)
      ->execute()
      ->first();

    if (!is_array($processor)) {
      throw new \RuntimeException('Failed to create PaymentProcessor');
    }

    return (int) $processor['id'];
  }

  /**
   * Create a contact.
   *
   * @return int
   *   The contact ID.
   */
  private function createContact(): int {
    $contact = \Civi\Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Test')
      ->addValue('last_name', 'User')
      ->execute()
      ->first();

    if (!is_array($contact)) {
      throw new \RuntimeException('Failed to create Contact');
    }

    return (int) $contact['id'];
  }

  /**
   * Create a recurring contribution.
   *
   * @param int $contactId
   *   The contact ID.
   * @param int $processorId
   *   The payment processor ID.
   * @param array<string, mixed> $params
   *   Additional parameters.
   *
   * @return int
   *   The recurring contribution ID.
   */
  private function createRecurringContribution(int $contactId, int $processorId, array $params = []): int {
    $defaults = [
      'contact_id' => $contactId,
      'payment_processor_id' => $processorId,
      'amount' => 50.00,
      'currency' => 'GBP',
      'financial_type_id:name' => 'Donation',
      'frequency_unit:name' => 'month',
      'frequency_interval' => 1,
      'start_date' => date('Y-m-d', strtotime('-6 months')),
      'contribution_status_id:name' => 'In Progress',
      'next_sched_contribution_date' => date('Y-m-d'),
    ];

    $values = array_merge($defaults, $params);

    $recur = \Civi\Api4\ContributionRecur::create(FALSE)
      ->setValues($values)
      ->execute()
      ->first();

    if (!is_array($recur)) {
      throw new \RuntimeException('Failed to create ContributionRecur');
    }

    return (int) $recur['id'];
  }

  /**
   * Create a contribution.
   *
   * @param int $contactId
   *   The contact ID.
   * @param int $recurId
   *   The recurring contribution ID.
   * @param array<string, mixed> $params
   *   Additional parameters.
   *
   * @return int
   *   The contribution ID.
   */
  private function createContribution(int $contactId, int $recurId, array $params = []): int {
    $defaults = [
      'contact_id' => $contactId,
      'contribution_recur_id' => $recurId,
      'financial_type_id:name' => 'Donation',
      'total_amount' => 50.00,
      'currency' => 'GBP',
      'contribution_status_id:name' => 'Completed',
      'receive_date' => date('Y-m-d'),
    ];

    $values = array_merge($defaults, $params);

    $contribution = \Civi\Api4\Contribution::create(FALSE)
      ->setValues($values)
      ->execute()
      ->first();

    if (!is_array($contribution)) {
      throw new \RuntimeException('Failed to create Contribution');
    }

    return (int) $contribution['id'];
  }

  /**
   * Create a membership linked to a recurring contribution.
   *
   * @param int $contactId
   *   The contact ID.
   * @param int $recurId
   *   The recurring contribution ID.
   *
   * @return int
   *   The membership ID.
   */
  private function createMembership(int $contactId, int $recurId): int {
    // Get or create membership type.
    $membershipType = \Civi\Api4\MembershipType::get(FALSE)
      ->addSelect('id')
      ->execute()
      ->first();

    if (!is_array($membershipType)) {
      $membershipType = \Civi\Api4\MembershipType::create(FALSE)
        ->addValue('name', 'Test Membership')
        ->addValue('member_of_contact_id', $contactId)
        ->addValue('financial_type_id:name', 'Member Dues')
        ->addValue('duration_unit', 'year')
        ->addValue('duration_interval', 1)
        ->addValue('period_type', 'rolling')
        ->execute()
        ->first();
    }

    if (!is_array($membershipType)) {
      throw new \RuntimeException('Failed to create MembershipType');
    }

    $membership = \Civi\Api4\Membership::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('membership_type_id', $membershipType['id'])
      ->addValue('contribution_recur_id', $recurId)
      ->addValue('join_date', date('Y-m-d'))
      ->addValue('start_date', date('Y-m-d'))
      ->addValue('status_id:name', 'Current')
      ->execute()
      ->first();

    if (!is_array($membership)) {
      throw new \RuntimeException('Failed to create Membership');
    }

    return (int) $membership['id'];
  }

}
