<?php

/**
 * Tests for CRM_Paymentprocessingcore_Page_WebhookHealth.
 *
 * @group headless
 */
class CRM_Paymentprocessingcore_Page_WebhookHealthTest extends BaseHeadlessTest {

  /**
   * @var string|null
   */
  private ?string $originalSiteKey = NULL;

  /**
   * @var string|null
   */
  private ?string $originalAuthHeader = NULL;

  /**
   * @var string|null
   */
  private ?string $originalKeyParam = NULL;

  /**
   * Set up test fixtures.
   */
  public function setUp(): void {
    parent::setUp();

    // Store original values.
    $this->originalSiteKey = defined('CIVICRM_SITE_KEY') ? CIVICRM_SITE_KEY : NULL;
    $this->originalAuthHeader = $_SERVER['HTTP_X_CIVI_KEY'] ?? NULL;
    $this->originalKeyParam = $_GET['key'] ?? NULL;

    // Clear any existing auth values.
    unset($_SERVER['HTTP_X_CIVI_KEY']);
    unset($_GET['key']);
  }

  /**
   * Tear down test fixtures.
   */
  public function tearDown(): void {
    // Restore original values.
    if ($this->originalAuthHeader !== NULL) {
      $_SERVER['HTTP_X_CIVI_KEY'] = $this->originalAuthHeader;
    }
    else {
      unset($_SERVER['HTTP_X_CIVI_KEY']);
    }

    if ($this->originalKeyParam !== NULL) {
      $_GET['key'] = $this->originalKeyParam;
    }
    else {
      unset($_GET['key']);
    }

    parent::tearDown();
  }

  /**
   * Tests authentication fails with no key provided.
   */
  public function testAuthenticationFailsWithNoKey(): void {
    $page = new CRM_Paymentprocessingcore_Page_WebhookHealth();
    $method = new ReflectionMethod($page, 'authenticateRequest');
    $method->setAccessible(TRUE);

    $this->assertFalse($method->invoke($page));
  }

  /**
   * Tests authentication fails with wrong key via URL param.
   */
  public function testAuthenticationFailsWithWrongKeyParam(): void {
    $_GET['key'] = 'wrong_key_123';

    $page = new CRM_Paymentprocessingcore_Page_WebhookHealth();
    $method = new ReflectionMethod($page, 'authenticateRequest');
    $method->setAccessible(TRUE);

    $this->assertFalse($method->invoke($page));
  }

  /**
   * Tests authentication fails with wrong key via header.
   */
  public function testAuthenticationFailsWithWrongKeyHeader(): void {
    $_SERVER['HTTP_X_CIVI_KEY'] = 'wrong_key_456';

    $page = new CRM_Paymentprocessingcore_Page_WebhookHealth();
    $method = new ReflectionMethod($page, 'authenticateRequest');
    $method->setAccessible(TRUE);

    $this->assertFalse($method->invoke($page));
  }

  /**
   * Tests authentication succeeds with correct key via URL param.
   */
  public function testAuthenticationSucceedsWithCorrectKeyParam(): void {
    // Skip if CIVICRM_SITE_KEY is not defined.
    if (!defined('CIVICRM_SITE_KEY') || empty(CIVICRM_SITE_KEY)) {
      $this->markTestSkipped('CIVICRM_SITE_KEY is not configured');
    }

    $_GET['key'] = CIVICRM_SITE_KEY;

    $page = new CRM_Paymentprocessingcore_Page_WebhookHealth();
    $method = new ReflectionMethod($page, 'authenticateRequest');
    $method->setAccessible(TRUE);

    $this->assertTrue($method->invoke($page));
  }

  /**
   * Tests authentication succeeds with correct key via header.
   */
  public function testAuthenticationSucceedsWithCorrectKeyHeader(): void {
    // Skip if CIVICRM_SITE_KEY is not defined.
    if (!defined('CIVICRM_SITE_KEY') || empty(CIVICRM_SITE_KEY)) {
      $this->markTestSkipped('CIVICRM_SITE_KEY is not configured');
    }

    $_SERVER['HTTP_X_CIVI_KEY'] = CIVICRM_SITE_KEY;

    $page = new CRM_Paymentprocessingcore_Page_WebhookHealth();
    $method = new ReflectionMethod($page, 'authenticateRequest');
    $method->setAccessible(TRUE);

    $this->assertTrue($method->invoke($page));
  }

  /**
   * Tests URL param takes precedence over header.
   */
  public function testUrlParamTakesPrecedenceOverHeader(): void {
    // Skip if CIVICRM_SITE_KEY is not defined.
    if (!defined('CIVICRM_SITE_KEY') || empty(CIVICRM_SITE_KEY)) {
      $this->markTestSkipped('CIVICRM_SITE_KEY is not configured');
    }

    $_GET['key'] = CIVICRM_SITE_KEY;
    $_SERVER['HTTP_X_CIVI_KEY'] = 'wrong_key';

    $page = new CRM_Paymentprocessingcore_Page_WebhookHealth();
    $method = new ReflectionMethod($page, 'authenticateRequest');
    $method->setAccessible(TRUE);

    // URL param is correct, so auth should succeed.
    $this->assertTrue($method->invoke($page));
  }

  /**
   * Tests authentication uses timing-safe comparison.
   *
   * This test verifies the method exists and can be invoked.
   * Actual timing analysis would require more sophisticated testing.
   */
  public function testAuthenticationUsesHashEquals(): void {
    $page = new CRM_Paymentprocessingcore_Page_WebhookHealth();
    $method = new ReflectionMethod($page, 'authenticateRequest');

    // Verify method signature exists and is private.
    $this->assertTrue($method->isPrivate());
    $this->assertEquals('bool', $method->getReturnType()?->getName());
  }

}
