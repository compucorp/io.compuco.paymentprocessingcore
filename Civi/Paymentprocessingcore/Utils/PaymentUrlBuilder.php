<?php

namespace Civi\Paymentprocessingcore\Utils;

/**
 * Utility class for building payment processor redirect URLs
 *
 * Provides generic URL building for success/cancel redirects that works
 * with Stripe, GoCardless, ITAS, and other payment processors.
 */
class PaymentUrlBuilder {

  /**
   * Build success URL for payment processor redirect
   *
   * Returns user to the thank-you page after successful payment.
   * Supports processor-specific parameters (e.g., session_id, redirect_flow_id).
   * Matches RequireActionHandler URL format for consistency.
   *
   * @param int $contributionId CiviCRM contribution ID
   * @param array<string,mixed> $params Must include: contributionPageID, qfKey, contactID
   * @param array<string,mixed> $additionalParams Processor-specific params (e.g., {CHECKOUT_SESSION_ID})
   * @return string Absolute URL to thank-you page with parameters
   */
  public static function buildSuccessUrl(int $contributionId, array $params, array $additionalParams = []): string {
    $queryParams = [
      'id' => $params['contributionPageID'] ?? NULL,
      '_qf_ThankYou_display' => 1,
      'qfKey' => $params['qfKey'] ?? NULL,
      'cid' => $params['contactID'] ?? NULL,
    ];

    // Add processor-specific parameters (e.g., session_id for Stripe)
    foreach ($additionalParams as $key => $value) {
      $queryParams[$key] = $value;
    }

    return \CRM_Utils_System::url(
      'civicrm/contribute/transact',
      $queryParams,
      TRUE,
      NULL,
      FALSE
    );
  }

  /**
   * Build cancel URL for payment processor redirect
   *
   * Returns user to the contribution page main form with error/cancel message.
   * Matches RequireActionHandler URL format for consistency.
   *
   * @param int $contributionId CiviCRM contribution ID
   * @param array<string,mixed> $params Must include: contributionPageID, qfKey, contactID
   * @return string Absolute URL to contribution page with cancel flag
   */
  public static function buildCancelUrl(int $contributionId, array $params): string {
    $queryParams = [
      'id' => $params['contributionPageID'] ?? NULL,
      '_qf_Main_display' => 1,
      'qfKey' => $params['qfKey'] ?? NULL,
      'cancel' => 1,
      'cid' => $params['contactID'] ?? NULL,
    // For logging/debugging
      'contribution_id' => $contributionId,
    ];

    return \CRM_Utils_System::url(
      'civicrm/contribute/transact',
      $queryParams,
      TRUE,
      NULL,
      FALSE
    );
  }

  /**
   * Build error URL for payment processor error redirects
   *
   * Returns user to the contribution page with error message.
   *
   * @param int $contributionId CiviCRM contribution ID
   * @param array<string,mixed> $params Must include: contributionPageID, qfKey
   * @param string $errorMessage Optional error message to display
   * @return string Absolute URL to contribution page with error flag
   */
  public static function buildErrorUrl(int $contributionId, array $params, string $errorMessage = ''): string {
    $queryParams = [
      'id' => $params['contributionPageID'] ?? NULL,
      '_qf_Main_display' => 1,
      'qfKey' => $params['qfKey'] ?? NULL,
      'error' => 1,
      'contribution_id' => $contributionId,
    ];

    if (!empty($errorMessage)) {
      $queryParams['error_message'] = $errorMessage;
    }

    return \CRM_Utils_System::url(
      'civicrm/contribute/transact',
      $queryParams,
      TRUE,
      NULL,
      FALSE
    );
  }

  /**
   * Build event registration success URL
   *
   * For processors used with event registration instead of contribution pages.
   *
   * @param int $participantId CiviCRM participant ID
   * @param array<string,mixed> $params Must include: eventID, qfKey
   * @param array<string,mixed> $additionalParams Processor-specific params
   * @return string Absolute URL to event thank-you page
   */
  public static function buildEventSuccessUrl(int $participantId, array $params, array $additionalParams = []): string {
    $queryParams = [
      'id' => $params['eventID'] ?? NULL,
      '_qf_ThankYou_display' => 1,
      'qfKey' => $params['qfKey'] ?? NULL,
    ];

    foreach ($additionalParams as $key => $value) {
      $queryParams[$key] = $value;
    }

    return \CRM_Utils_System::url(
      'civicrm/event/register',
      $queryParams,
      TRUE,
      NULL,
      FALSE
    );
  }

  /**
   * Build event registration cancel URL
   *
   * @param int $participantId CiviCRM participant ID
   * @param array<string,mixed> $params Must include: eventID, qfKey
   * @return string Absolute URL to event registration page with cancel flag
   */
  public static function buildEventCancelUrl(int $participantId, array $params): string {
    $queryParams = [
      'id' => $params['eventID'] ?? NULL,
      '_qf_Register_display' => 1,
      'qfKey' => $params['qfKey'] ?? NULL,
      'cancel' => 1,
      'participant_id' => $participantId,
    ];

    return \CRM_Utils_System::url(
      'civicrm/event/register',
      $queryParams,
      TRUE,
      NULL,
      FALSE
    );
  }

  /**
   * Build IPN notification URL for payment processor callbacks
   *
   * Generic IPN endpoint URL that payment processors (Stripe, GoCardless, etc.)
   * should redirect to after hosted payment flows (Checkout, redirect flows).
   *
   * The IPN handler will then process the payment and redirect to thank-you page.
   *
   * @param int $paymentProcessorId Payment processor ID
   * @param array<string,mixed> $additionalParams Processor-specific params
   *   Examples:
   *   - Stripe Checkout: ['session_id' => '{CHECKOUT_SESSION_ID}']
   *   - GoCardless: ['redirect_flow_id' => '{redirect_flow_id}']
   *
   * @return string Absolute URL to IPN endpoint
   */
  public static function buildIpnUrl(int $paymentProcessorId, array $additionalParams = []): string {
    return \CRM_Utils_System::url(
      'civicrm/payment/ipn/' . $paymentProcessorId,
      $additionalParams,
      TRUE,
      NULL,
      FALSE
    );
  }

}
