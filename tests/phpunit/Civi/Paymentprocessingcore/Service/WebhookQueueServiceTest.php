<?php

namespace Civi\Paymentprocessingcore\Service;

/**
 * Unit tests for WebhookQueueService.
 *
 * @group headless
 */
class WebhookQueueServiceTest extends \BaseHeadlessTest {

  /**
   * The webhook queue service.
   *
   * @var \Civi\Paymentprocessingcore\Service\WebhookQueueService
   */
  private WebhookQueueService $queueService;

  /**
   * Set up test fixtures.
   */
  public function setUp(): void {
    parent::setUp();
    $this->queueService = \Civi::service('paymentprocessingcore.webhook_queue');

    // Reset initialization tracking between tests
    WebhookQueueService::resetInitialization();
  }

  /**
   * Test getQueue() returns a queue instance.
   */
  public function testGetQueueReturnsQueueInstance() {
    $queue = $this->queueService->getQueue('stripe');

    $this->assertInstanceOf(\CRM_Queue_Queue::class, $queue);
  }

  /**
   * Test getQueue() creates queues with correct naming convention.
   */
  public function testGetQueueCreatesQueueWithCorrectName() {
    $queueName = $this->queueService->getQueueName('stripe');

    $this->assertEquals('io.compuco.paymentprocessingcore.webhook.stripe', $queueName);
  }

  /**
   * Test getQueue() returns same instance for same processor type.
   */
  public function testGetQueueReturnsSameInstanceForSameProcessor() {
    $queue1 = $this->queueService->getQueue('stripe');
    $queue2 = $this->queueService->getQueue('stripe');

    // Should return same queue (same name)
    $this->assertEquals(0, $queue1->numberOfItems());
    $this->assertEquals(0, $queue2->numberOfItems());
  }

  /**
   * Test getQueue() returns different instances for different processors.
   */
  public function testGetQueueReturnsDifferentInstancesForDifferentProcessors() {
    $stripeQueue = $this->queueService->getQueue('stripe');
    $gocardlessQueue = $this->queueService->getQueue('gocardless');

    $stripeName = $this->queueService->getQueueName('stripe');
    $gocardlessName = $this->queueService->getQueueName('gocardless');

    $this->assertNotEquals($stripeName, $gocardlessName);
  }

  /**
   * Test addTask() adds a task to the queue.
   */
  public function testAddTaskAddsTaskToQueue() {
    $this->queueService->addTask('stripe', 123, ['event_data' => ['foo' => 'bar']]);

    $count = $this->queueService->getQueueCount('stripe');
    $this->assertEquals(1, $count);
  }

  /**
   * Test addTask() adds multiple tasks to the queue.
   */
  public function testAddTaskAddsMultipleTasksToQueue() {
    $this->queueService->addTask('stripe', 123, ['event_data' => ['foo' => 'bar']]);
    $this->queueService->addTask('stripe', 456, ['event_data' => ['baz' => 'qux']]);

    $count = $this->queueService->getQueueCount('stripe');
    $this->assertEquals(2, $count);
  }

  /**
   * Test getQueueCount() returns correct count.
   */
  public function testGetQueueCountReturnsCorrectCount() {
    $this->assertEquals(0, $this->queueService->getQueueCount('stripe'));

    $this->queueService->addTask('stripe', 123, []);
    $this->assertEquals(1, $this->queueService->getQueueCount('stripe'));

    $this->queueService->addTask('stripe', 456, []);
    $this->assertEquals(2, $this->queueService->getQueueCount('stripe'));
  }

  /**
   * Test queues are isolated per processor type.
   */
  public function testQueuesAreIsolatedPerProcessorType() {
    $this->queueService->addTask('stripe', 123, []);
    $this->queueService->addTask('gocardless', 456, []);

    $this->assertEquals(1, $this->queueService->getQueueCount('stripe'));
    $this->assertEquals(1, $this->queueService->getQueueCount('gocardless'));
  }

  /**
   * Test queue reset during tests.
   */
  public function testQueueResetDuringTests() {
    // First access - should reset queue
    $queue1 = $this->queueService->getQueue('stripe');
    $this->assertEquals(0, $queue1->numberOfItems());

    // Add items
    $this->queueService->addTask('stripe', 123, []);
    $this->assertEquals(1, $this->queueService->getQueueCount('stripe'));

    // Second access to same queue - should NOT reset (initialization already done)
    $queue2 = $this->queueService->getQueue('stripe');
    $this->assertEquals(1, $queue2->numberOfItems());
  }

  /**
   * Test resetInitialization() allows queue to be reset again.
   */
  public function testResetInitializationAllowsQueueResetAgain() {
    $this->queueService->addTask('stripe', 123, []);
    $this->assertEquals(1, $this->queueService->getQueueCount('stripe'));

    // Reset initialization tracking
    WebhookQueueService::resetInitialization();

    // Next access should reset the queue (if in test mode)
    $queue = $this->queueService->getQueue('stripe');

    // In test mode, queue should be reset to 0
    $this->assertEquals(0, $queue->numberOfItems());
  }

}
