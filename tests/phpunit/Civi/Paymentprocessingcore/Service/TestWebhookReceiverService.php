<?php

namespace Civi\Paymentprocessingcore\Service;

/**
 * Concrete implementation of WebhookReceiverService for testing.
 */
class TestWebhookReceiverService extends WebhookReceiverService {

  public function getProcessorType(): string {
    return 'test';
  }

  public function handleRequest(string $payload, string $signature): void {
    // Not tested - processor-specific implementation
  }

  /**
   * Expose protected methods for testing.
   */
  public function publicSaveWebhookEvent(
    string $eventId,
    string $eventType,
    ?int $paymentAttemptId = NULL
  ): ?int {
    return $this->saveWebhookEvent($eventId, $eventType, $paymentAttemptId);
  }

  public function publicQueueWebhook(int $webhookId, array $eventData): void {
    $this->queueWebhook($webhookId, $eventData);
  }

  public function publicFindPaymentAttemptId(?string $processorPaymentId): ?int {
    return $this->findPaymentAttemptId($processorPaymentId);
  }

}
