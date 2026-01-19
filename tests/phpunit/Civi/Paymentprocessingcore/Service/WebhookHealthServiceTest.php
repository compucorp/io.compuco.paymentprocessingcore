<?php

use Civi\Paymentprocessingcore\Service\WebhookHealthService;
use CRM_Paymentprocessingcore_BAO_PaymentWebhook as PaymentWebhook;

/**
 * Tests for WebhookHealthService.
 *
 * @group headless
 */
class Civi_Paymentprocessingcore_Service_WebhookHealthServiceTest extends BaseHeadlessTest {

  /**
   * @var \Civi\Paymentprocessingcore\Service\WebhookHealthService
   */
  private WebhookHealthService $service;

  /**
   * Set up test fixtures.
   */
  public function setUp(): void {
    parent::setUp();
    $this->service = new WebhookHealthService();
  }

  /**
   * Tests healthy status when no issues exist.
   */
  public function testHealthyStatusWhenNoIssues(): void {
    // Create some processed webhooks.
    $this->createWebhook('processed');
    $this->createWebhook('processed');

    $health = $this->service->getHealthStatus();

    $this->assertEquals(WebhookHealthService::STATUS_HEALTHY, $health['status']);
    $this->assertEquals(0, $health['totals']['pending']);
    $this->assertEquals(0, $health['totals']['stuck']);
  }

  /**
   * Tests degraded status with permanent errors.
   */
  public function testDegradedStatusWithPermanentErrors(): void {
    $this->createWebhook('permanent_error');

    $health = $this->service->getHealthStatus();

    $this->assertEquals(WebhookHealthService::STATUS_DEGRADED, $health['status']);
    $this->assertEquals(1, $health['totals']['permanent_errors']);
  }

  /**
   * Tests unhealthy status with stuck webhooks.
   */
  public function testUnhealthyStatusWithStuckWebhooks(): void {
    // Create stuck webhook (processing > 30 minutes).
    PaymentWebhook::create([
      'event_id' => 'evt_stuck_' . uniqid(),
      'processor_type' => 'stripe',
      'event_type' => 'payment_intent.succeeded',
      'status' => 'processing',
      'processing_started_at' => date('Y-m-d H:i:s', strtotime('-35 minutes')),
    ]);

    $health = $this->service->getHealthStatus();

    $this->assertEquals(WebhookHealthService::STATUS_UNHEALTHY, $health['status']);
    $this->assertGreaterThan(0, $health['totals']['stuck']);
  }

  /**
   * Tests processor stats are grouped by processor.
   */
  public function testProcessorStatsGroupByProcessor(): void {
    $this->createWebhook('new', 'stripe');
    $this->createWebhook('processed', 'stripe');
    $this->createWebhook('new', 'gocardless');

    $health = $this->service->getHealthStatus();

    $this->assertArrayHasKey('stripe', $health['processors']);
    $this->assertArrayHasKey('gocardless', $health['processors']);
    $this->assertEquals(1, $health['processors']['stripe']['pending']);
    $this->assertEquals(1, $health['processors']['gocardless']['pending']);
  }

  /**
   * Tests oldest pending age calculation.
   */
  public function testOldestPendingAgeCalculation(): void {
    // Create webhook 5 minutes ago.
    PaymentWebhook::create([
      'event_id' => 'evt_old_' . uniqid(),
      'processor_type' => 'stripe',
      'event_type' => 'payment_intent.succeeded',
      'status' => 'new',
      'created_date' => date('Y-m-d H:i:s', strtotime('-5 minutes')),
    ]);

    $health = $this->service->getHealthStatus();

    $this->assertGreaterThanOrEqual(5, $health['oldest_pending_age_minutes']);
    $this->assertLessThan(10, $health['oldest_pending_age_minutes']);
  }

  /**
   * Tests last processed timestamp is returned.
   */
  public function testLastProcessedAtReturned(): void {
    $this->createWebhook('processed');

    $health = $this->service->getHealthStatus();

    $this->assertNotNull($health['last_processed_at']);
    // Should be ISO 8601 format.
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $health['last_processed_at']);
  }

  /**
   * Tests thresholds are included in response.
   */
  public function testThresholdsIncluded(): void {
    $health = $this->service->getHealthStatus();

    $this->assertArrayHasKey('thresholds', $health);
    $this->assertArrayHasKey('stuck_reset_limit', $health['thresholds']);
    $this->assertArrayHasKey('stuck_exceeds_limit', $health['thresholds']);
    $this->assertEquals(PaymentWebhook::MAX_STUCK_RESET_LIMIT, $health['thresholds']['stuck_reset_limit']);
  }

  /**
   * Tests processed last hour count.
   */
  public function testProcessedLastHourCount(): void {
    // Create processed webhook with recent timestamp.
    PaymentWebhook::create([
      'event_id' => 'evt_recent_' . uniqid(),
      'processor_type' => 'stripe',
      'event_type' => 'payment_intent.succeeded',
      'status' => 'processed',
      'processed_at' => date('Y-m-d H:i:s', strtotime('-30 minutes')),
    ]);

    // Create processed webhook with old timestamp.
    PaymentWebhook::create([
      'event_id' => 'evt_old_processed_' . uniqid(),
      'processor_type' => 'stripe',
      'event_type' => 'payment_intent.succeeded',
      'status' => 'processed',
      'processed_at' => date('Y-m-d H:i:s', strtotime('-2 hours')),
    ]);

    $health = $this->service->getHealthStatus();

    $this->assertEquals(1, $health['totals']['processed_last_hour']);
  }

  /**
   * Tests error count includes both error and permanent_error.
   */
  public function testErrorCountIncludesBothTypes(): void {
    $this->createWebhook('error', 'stripe');
    $this->createWebhook('permanent_error', 'stripe');

    $stats = $this->service->getProcessorStats();

    $this->assertEquals(2, $stats['stripe']['errors']);
  }

  /**
   * Tests healthy status with empty database.
   */
  public function testHealthyStatusWithEmptyDatabase(): void {
    $health = $this->service->getHealthStatus();

    $this->assertEquals(WebhookHealthService::STATUS_HEALTHY, $health['status']);
    $this->assertEquals(0, $health['totals']['pending']);
    $this->assertEquals(0, $health['totals']['stuck']);
    $this->assertEquals(0, $health['totals']['permanent_errors']);
    $this->assertNull($health['oldest_pending_age_minutes']);
    $this->assertNull($health['last_processed_at']);
  }

  /**
   * Helper method to create a webhook with given status.
   *
   * @param string $status
   *   The webhook status.
   * @param string $processor
   *   The processor type.
   */
  private function createWebhook(string $status, string $processor = 'stripe'): void {
    $params = [
      'event_id' => 'evt_' . uniqid(),
      'processor_type' => $processor,
      'event_type' => 'payment_intent.succeeded',
      'status' => $status,
    ];

    if (in_array($status, ['processed', 'permanent_error'], TRUE)) {
      $params['processed_at'] = date('Y-m-d H:i:s');
    }

    PaymentWebhook::create($params);
  }

}
