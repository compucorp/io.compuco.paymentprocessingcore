<?php

namespace Civi\Paymentprocessingcore\Service;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionPage;
use Civi\Paymentprocessingcore\Exception\ContributionCompletionException;

/**
 * Tests for ContributionCompletionService.
 *
 * @group headless
 */
class ContributionCompletionServiceTest extends \BaseHeadlessTest {

  /**
   * @var \Civi\Paymentprocessingcore\Service\ContributionCompletionService
   */
  private $service;

  /**
   * @var int
   */
  private $contactId;

  /**
   * @var int
   */
  private $contributionPageId;

  /**
   * Set up test fixtures.
   */
  public function setUp(): void {
    parent::setUp();

    // Get service from container
    $this->service = \Civi::service('paymentprocessingcore.contribution_completion');

    // Create test contact
    $this->contactId = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Test')
      ->addValue('last_name', 'Donor')
      ->execute()
      ->first()['id'];

    // Create test contribution page
    $this->contributionPageId = ContributionPage::create(FALSE)
      ->addValue('title', 'Test Contribution Page')
      ->addValue('financial_type_id:name', 'Donation')
      ->addValue('is_email_receipt', TRUE)
      ->execute()
      ->first()['id'];
  }

  /**
   * Tests completing a Pending contribution successfully.
   */
  public function testCompletesPendingContribution(): void {
    $contributionId = $this->createPendingContribution();
    $transactionId = 'ch_test_12345';
    $feeAmount = 2.50;

    $result = $this->service->complete($contributionId, $transactionId, $feeAmount, FALSE);

    $this->assertTrue($result['success']);
    $this->assertEquals($contributionId, $result['contribution_id']);
    $this->assertFalse($result['already_completed']);

    // Verify contribution status updated
    $contribution = Contribution::get(FALSE)
      ->addSelect('contribution_status_id:name', 'trxn_id')
      ->addWhere('id', '=', $contributionId)
      ->execute()
      ->first();

    $this->assertEquals('Completed', $contribution['contribution_status_id:name']);
    $this->assertEquals($transactionId, $contribution['trxn_id']);
  }

  /**
   * Tests idempotency - completing already completed contribution returns success.
   */
  public function testIdempotencyAlreadyCompleted(): void {
    $contributionId = $this->createPendingContribution();
    $transactionId = 'ch_test_67890';

    // Complete first time
    $this->service->complete($contributionId, $transactionId, NULL, FALSE);

    // Complete second time (idempotency check)
    $result = $this->service->complete($contributionId, $transactionId, NULL, FALSE);

    $this->assertTrue($result['success']);
    $this->assertTrue($result['already_completed']);
  }

  /**
   * Tests completing non-Pending contribution throws exception.
   */
  public function testThrowsExceptionForNonPendingContribution(): void {
    $contributionId = $this->createPendingContribution();

    $this->expectException(ContributionCompletionException::class);
    $this->expectExceptionMessage("status is 'Cancelled', expected 'Pending'");

    // Mark as Cancelled first
    Contribution::update(FALSE)
      ->addWhere('id', '=', $contributionId)
      ->addValue('contribution_status_id:name', 'Cancelled')
      ->execute();

    $this->service->complete($contributionId, 'ch_test_cancelled', NULL, FALSE);
  }

  /**
   * Tests completing invalid contribution ID throws exception.
   */
  public function testThrowsExceptionForInvalidContributionId(): void {
    $invalidId = 999999;

    $this->expectException(ContributionCompletionException::class);
    $this->expectExceptionMessage('Contribution not found');

    $this->service->complete($invalidId, 'ch_test_invalid', NULL, FALSE);
  }

  /**
   * Tests fee amount is recorded correctly.
   */
  public function testRecordsFeeAmount(): void {
    $contributionId = $this->createPendingContribution(100.00);
    $feeAmount = 3.20;

    $this->service->complete($contributionId, 'ch_test_fee', $feeAmount, FALSE);

    $contribution = Contribution::get(FALSE)
      ->addSelect('fee_amount', 'net_amount')
      ->addWhere('id', '=', $contributionId)
      ->execute()
      ->first();

    $this->assertEquals($feeAmount, $contribution['fee_amount']);
    // 100.00 - 3.20.
    $this->assertEquals(96.80, $contribution['net_amount']);
  }

  /**
   * Tests service is accessible via container.
   */
  public function testServiceAccessibleViaContainer(): void {
    $service = \Civi::service('paymentprocessingcore.contribution_completion');

    $this->assertInstanceOf(ContributionCompletionService::class, $service);
  }

  /**
   * Helper: Create Pending contribution.
   */
  private function createPendingContribution(float $amount = 100.00, ?int $contributionPageId = NULL): int {
    $params = [
      'contact_id' => $this->contactId,
      'financial_type_id:name' => 'Donation',
      'total_amount' => $amount,
      'currency' => 'GBP',
      'contribution_status_id:name' => 'Pending',
    ];

    if ($contributionPageId !== NULL) {
      $params['contribution_page_id'] = $contributionPageId;
    }

    return Contribution::create(FALSE)
      ->setValues($params)
      ->execute()
      ->first()['id'];
  }

}
