<?php

namespace Civi\Paymentprocessingcore\Service;

/**
 * Another concrete implementation for testing different processor.
 */
class OtherWebhookReceiverService extends WebhookReceiverService {

  public function getProcessorType(): string {
    return 'other';
  }

  public function handleRequest(string $payload, string $signature): void {
    // Not tested
  }

  public function publicSaveWebhookEvent(
    string $eventId,
    string $eventType,
    ?int $paymentAttemptId = NULL
  ): ?int {
    return $this->saveWebhookEvent($eventId, $eventType, $paymentAttemptId);
  }

}
