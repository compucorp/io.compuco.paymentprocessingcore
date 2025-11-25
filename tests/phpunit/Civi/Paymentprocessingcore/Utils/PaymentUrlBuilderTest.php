<?php

use Civi\Paymentprocessingcore\Utils\PaymentUrlBuilder;

/**
 * Tests for PaymentUrlBuilder utility class.
 *
 * @group headless
 */
class PaymentUrlBuilderTest extends BaseHeadlessTest {

  public function testBuildIpnUrlWithoutParams() {
    $url = PaymentUrlBuilder::buildIpnUrl(5);

    $this->assertStringContainsString('civicrm/payment/ipn/5', $url);
    $this->assertStringContainsString('http', $url);
  }

  public function testBuildIpnUrlWithStripeSessionId() {
    $url = PaymentUrlBuilder::buildIpnUrl(5, ['session_id' => '{CHECKOUT_SESSION_ID}']);

    $this->assertStringContainsString('civicrm/payment/ipn/5', $url);
    $this->assertStringContainsString('session_id=%7BCHECKOUT_SESSION_ID%7D', $url);
  }

  public function testBuildIpnUrlWithGoCardlessRedirectFlowId() {
    $url = PaymentUrlBuilder::buildIpnUrl(10, ['redirect_flow_id' => '{redirect_flow_id}']);

    $this->assertStringContainsString('civicrm/payment/ipn/10', $url);
    $this->assertStringContainsString('redirect_flow_id', $url);
  }

  public function testBuildIpnUrlWithMultipleParams() {
    $url = PaymentUrlBuilder::buildIpnUrl(3, [
      'session_id' => 'cs_test_123',
      'foo' => 'bar',
    ]);

    $this->assertStringContainsString('civicrm/payment/ipn/3', $url);
    $this->assertStringContainsString('session_id=cs_test_123', $url);
    $this->assertStringContainsString('foo=bar', $url);
  }

  public function testBuildSuccessUrlWithBasicParams() {
    $url = PaymentUrlBuilder::buildSuccessUrl(100, [
      'contributionPageID' => 5,
      'qfKey' => 'abc123',
      'contactID' => 10,
    ]);

    $this->assertStringContainsString('civicrm/contribute/transact', $url);
    $this->assertStringContainsString('id=5', $url);
    $this->assertStringContainsString('qfKey=abc123', $url);
    $this->assertStringContainsString('cid=10', $url);
    $this->assertStringContainsString('_qf_ThankYou_display=1', $url);
  }

  public function testBuildSuccessUrlWithAdditionalParams() {
    $url = PaymentUrlBuilder::buildSuccessUrl(200, [
      'contributionPageID' => 7,
      'qfKey' => 'def456',
      'contactID' => 20,
    ], [
      'session_id' => 'cs_test_789',
    ]);

    $this->assertStringContainsString('session_id=cs_test_789', $url);
  }

  public function testBuildCancelUrl() {
    $url = PaymentUrlBuilder::buildCancelUrl(300, [
      'contributionPageID' => 8,
      'qfKey' => 'ghi789',
      'contactID' => 30,
    ]);

    $this->assertStringContainsString('civicrm/contribute/transact', $url);
    $this->assertStringContainsString('id=8', $url);
    $this->assertStringContainsString('cancel=1', $url);
    $this->assertStringContainsString('_qf_Main_display=1', $url);
    $this->assertStringContainsString('contribution_id=300', $url);
  }

  public function testBuildErrorUrl() {
    $url = PaymentUrlBuilder::buildErrorUrl(400, [
      'contributionPageID' => 9,
      'qfKey' => 'jkl012',
    ], 'Test error message');

    $this->assertStringContainsString('civicrm/contribute/transact', $url);
    $this->assertStringContainsString('error=1', $url);
    $this->assertStringContainsString('error_message=Test+error+message', $url);
  }

  public function testBuildEventSuccessUrl() {
    $url = PaymentUrlBuilder::buildEventSuccessUrl(500, [
      'eventID' => 15,
      'qfKey' => 'mno345',
    ]);

    $this->assertStringContainsString('civicrm/event/register', $url);
    $this->assertStringContainsString('id=15', $url);
    $this->assertStringContainsString('_qf_ThankYou_display=1', $url);
  }

  public function testBuildEventCancelUrl() {
    $url = PaymentUrlBuilder::buildEventCancelUrl(600, [
      'eventID' => 20,
      'qfKey' => 'pqr678',
    ]);

    $this->assertStringContainsString('civicrm/event/register', $url);
    $this->assertStringContainsString('id=20', $url);
    $this->assertStringContainsString('cancel=1', $url);
    $this->assertStringContainsString('participant_id=600', $url);
  }

}
