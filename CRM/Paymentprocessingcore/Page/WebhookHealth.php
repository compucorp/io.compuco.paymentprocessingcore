<?php

use Civi\Paymentprocessingcore\Service\WebhookHealthService;

/**
 * Webhook health check endpoint.
 *
 * URL: civicrm/webhook/health
 *
 * Returns JSON with webhook health metrics for external monitoring systems.
 * Requires authentication via CIVICRM_SITE_KEY.
 *
 * Authentication methods:
 * - URL parameter: ?key=YOUR_SITE_KEY
 * - Header: X-Civi-Key: YOUR_SITE_KEY
 *
 * Response codes:
 * - 200: healthy or degraded status
 * - 401: authentication failed
 * - 500: internal error
 * - 503: unhealthy status (service unavailable)
 *
 * @package CRM_Paymentprocessingcore_Page
 */
class CRM_Paymentprocessingcore_Page_WebhookHealth extends CRM_Core_Page {

  /**
   * Handle health check request.
   *
   * @return void
   */
  public function run(): void {
    CRM_Utils_System::setHttpHeader('Content-Type', 'application/json');

    // Validate authentication.
    if (!$this->authenticateRequest()) {
      http_response_code(401);
      echo json_encode([
        'error' => 'Unauthorized',
        'message' => 'Invalid or missing site key',
      ], JSON_THROW_ON_ERROR);
      CRM_Utils_System::civiExit();
    }

    try {
      /** @var \Civi\Paymentprocessingcore\Service\WebhookHealthService $healthService */
      $healthService = \Civi::service('paymentprocessingcore.webhook_health');
      $healthData = $healthService->getHealthStatus();

      // Set appropriate HTTP status based on health.
      $httpStatus = match ($healthData['status']) {
        WebhookHealthService::STATUS_HEALTHY => 200,
        WebhookHealthService::STATUS_DEGRADED => 200,
        WebhookHealthService::STATUS_UNHEALTHY => 503,
        default => 200,
      };

      http_response_code($httpStatus);
      echo json_encode($healthData, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
    catch (\Exception $e) {
      \Civi::log()->error('Webhook health check failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
      ]);
      http_response_code(500);
      echo json_encode([
        'error' => 'Internal server error',
        'message' => 'Health check failed',
      ], JSON_THROW_ON_ERROR);
    }

    CRM_Utils_System::civiExit();
  }

  /**
   * Authenticate the request using CIVICRM_SITE_KEY.
   *
   * Accepts the key via:
   * - URL parameter: ?key=VALUE
   * - HTTP header: X-Civi-Key: VALUE
   *
   * @return bool TRUE if authenticated, FALSE otherwise.
   */
  private function authenticateRequest(): bool {
    // Get site key from CiviCRM configuration.
    $siteKey = defined('CIVICRM_SITE_KEY') ? CIVICRM_SITE_KEY : '';

    // If no site key configured, deny all requests.
    if (empty($siteKey)) {
      \Civi::log()->warning('Webhook health check attempted but CIVICRM_SITE_KEY is not configured');
      return FALSE;
    }

    // Check URL parameter first, then header.
    $providedKey = $_GET['key'] ?? $_SERVER['HTTP_X_CIVI_KEY'] ?? '';

    if (empty($providedKey)) {
      return FALSE;
    }

    // Use timing-safe comparison.
    return hash_equals($siteKey, $providedKey);
  }

}
