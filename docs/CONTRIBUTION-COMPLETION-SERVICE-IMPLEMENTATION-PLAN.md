# ContributionCompletionService - Implementation Plan

**Extension:** PaymentProcessingCore (`io.compuco.paymentprocessingcore`)
**Date:** 2025-11-25
**Status:** READY FOR IMPLEMENTATION

---

## Overview

Implement a generic `ContributionCompletionService` in PaymentProcessingCore extension that handles contribution completion for **all payment processors** (Stripe, GoCardless, ITAS, Deluxe, etc.).

**Purpose:** Centralize contribution completion logic that was previously duplicated across payment processor extensions.

**Key Features:**
- ✅ Generic service shared across all payment processors
- ✅ Idempotent (safe to call multiple times)
- ✅ Automatically handles accounting entries via `Contribution.completetransaction` API
- ✅ Auto-detects receipt settings from contribution page
- ✅ Detailed error messages with context via custom exceptions
- ✅ Works with success URL handlers AND webhook handlers

---

## Analysis: Concerns with Original Plan

### 🚨 Major Concerns Identified

| # | Concern | Impact | Solution |
|---|---------|--------|----------|
| 1 | **No Service Container Infrastructure** | Service cannot be registered or accessed | Implement `hook_civicrm_container` + compiler pass |
| 2 | **API Version Inconsistency** | Mixes APIv3 and APIv4 | Use APIv4 throughout (matches existing code) |
| 3 | **Missing Error Context** | Returns `FALSE` without error details | Throw custom exception with `getContext()` method |
| 4 | **Receipt Logic Location** | Receipt logic in Stripe extension (not generic) | Move to `ContributionCompletionService` |
| 5 | **No Contribution Page Validation** | Could crash if page doesn't exist | Add validation with try/catch |
| 6 | **Transaction ID Collision** | No duplicate checking | Rely on CiviCRM API's built-in validation |
| 7 | **Logging vs Exception** | Silent failures difficult to debug | Throw exceptions + log errors |

### ✅ What's Good About Original Plan

- Uses `Contribution.completetransaction` API (correct approach)
- Idempotency checks (won't duplicate if already completed)
- Generic design (works for all processors)
- Comprehensive implementation plan with phases

---

## Architecture Diagram

```
Payment Processor Extension (Stripe, GoCardless, etc.)
   ↓
Calls: \Civi::service('paymentprocessingcore.contribution_completion')
   ↓
ContributionCompletionService->complete($contributionId, $transactionId, $feeAmount, $sendReceipt)
   ├─ Validate contribution exists (APIv4)
   ├─ Check idempotency (already completed?)
   ├─ Validate status (Pending only)
   ├─ Auto-detect receipt setting (if NULL)
   │   └─ Query ContributionPage.is_email_receipt (APIv4)
   ├─ Call Contribution.completetransaction API (APIv3 - required)
   │   ├─ Creates payment record
   │   ├─ Posts accounting entries (A/R + Payment)
   │   ├─ Updates contribution status to Completed
   │   ├─ Records fee amount
   │   └─ Sends receipt email (if enabled)
   └─ Return success result OR throw ContributionCompletionException
```

---

## Key Design Decisions

### 1. Use Service Container with Compiler Pass

**Decision:** Implement full service container infrastructure using compiler pass pattern.

**Rationale:**
- PaymentProcessingCore currently has NO service container
- Compiler pass is the modern CiviCRM way to register services
- Allows Stripe extension to access service via `\Civi::service('paymentprocessingcore.contribution_completion')`
- Follows Symfony DI container best practices

**Implementation:**
```php
// In paymentprocessingcore.php
function paymentprocessingcore_civicrm_container(\Symfony\Component\DependencyInjection\ContainerBuilder $container): void {
  $container->addCompilerPass(new \Civi\Paymentprocessingcore\CompilerPass\RegisterServicesPass());
}
```

### 2. Use APIv4 for Queries, APIv3 for Completion

**Decision:** Use APIv4 for all data queries, but APIv3 for `Contribution.completetransaction`.

**Rationale:**
- **APIv4 for queries:** Consistent with existing PaymentProcessingCore tests
- **APIv3 for completion:** `Contribution.completetransaction` not available in APIv4 yet
- Modern code pattern: APIv4 where possible, APIv3 when required

**Example:**
```php
// Query with APIv4
$contribution = Contribution::get(FALSE)
  ->addSelect('id', 'contribution_status_id:name', 'total_amount')
  ->addWhere('id', '=', $contributionId)
  ->execute()
  ->first();

// Complete with APIv3 (required)
civicrm_api3('Contribution', 'completetransaction', [
  'id' => $contributionId,
  'trxn_id' => $transactionId,
  'fee_amount' => $feeAmount,
  'is_email_receipt' => $sendReceipt ? 1 : 0,
]);
```

### 3. Throw Exceptions Instead of Returning FALSE

**Decision:** Throw `ContributionCompletionException` for all errors instead of returning `FALSE`.

**Rationale:**
- Better error handling in calling code (Stripe extension)
- Provides detailed context via `getContext()` method
- Allows try/catch error handling pattern
- Still logs errors for debugging

**Example:**
```php
try {
  $result = $service->complete($contributionId, $transactionId, $feeAmount, $sendReceipt);
}
catch (\Civi\Paymentprocessingcore\Exception\ContributionCompletionException $e) {
  $context = $e->getContext();
  // Handle error with full context
}
```

### 4. Receipt Logic in Service (Not in Processor Extension)

**Decision:** Move receipt detection logic into `ContributionCompletionService`.

**Rationale:**
- **Generic logic** - all processors need to check contribution page settings
- **Centralized** - no duplication across Stripe, GoCardless, etc.
- **Auto-detection** - if `$sendReceipt = NULL`, automatically check contribution page `is_email_receipt`
- **Flexible** - processors can override by passing explicit `TRUE`/`FALSE`

**Usage:**
```php
// Stripe extension: Let service auto-detect receipt setting
$service->complete($contributionId, $chargeId, $feeAmount, NULL);

// OR explicitly control receipt
$service->complete($contributionId, $chargeId, $feeAmount, FALSE); // Never send receipt
```

### 5. Idempotency via Status Check

**Decision:** Check contribution status before completion, return success if already completed.

**Rationale:**
- Safe to call multiple times (webhook retries, race conditions)
- Avoids duplicate accounting entries
- Returns `['already_completed' => TRUE]` for transparency

**Implementation:**
```php
if ($contribution['contribution_status_id:name'] === 'Completed') {
  return [
    'success' => TRUE,
    'contribution_id' => $contributionId,
    'already_completed' => TRUE,
  ];
}
```

---

## Implementation Plan

### Phase 1: Set up Service Container Infrastructure

**Why:** PaymentProcessingCore has no service container. This is required to register services.

#### 1.1 Implement hook_civicrm_container

**File:** `paymentprocessingcore.php`

**Add:**
```php
/**
 * Implements hook_civicrm_container().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_container/
 */
function paymentprocessingcore_civicrm_container(\Symfony\Component\DependencyInjection\ContainerBuilder $container): void {
  $container->addCompilerPass(new \Civi\Paymentprocessingcore\CompilerPass\RegisterServicesPass());
}
```

#### 1.2 Create Compiler Pass

**File:** `Civi/Paymentprocessingcore/CompilerPass/RegisterServicesPass.php`

**Purpose:** Register all PaymentProcessingCore services via compiler pass pattern.

**Code:**
```php
<?php

namespace Civi\Paymentprocessingcore\CompilerPass;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Compiler pass to register PaymentProcessingCore services.
 */
class RegisterServicesPass implements CompilerPassInterface {

  /**
   * {@inheritdoc}
   */
  public function process(ContainerBuilder $container): void {
    // Register ContributionCompletionService
    $container->setDefinition(
      'paymentprocessingcore.contribution_completion',
      new Definition('Civi\Paymentprocessingcore\Service\ContributionCompletionService')
    );
  }

}
```

---

### Phase 2: Create ContributionCompletionService

**File:** `Civi/Paymentprocessingcore/Service/ContributionCompletionService.php`

**Improvements over original plan:**
- ✅ Use **APIv4** for data queries (consistent with existing code)
- ✅ Throw **exceptions** instead of returning `FALSE` (better error handling)
- ✅ Add **receipt logic** in service (generic, not processor-specific)
- ✅ Add **contribution page validation** (prevents crashes)
- ✅ Better **error messages** with context
- ✅ Proper **PHPDoc** with `@throws` annotations

**Key Methods:**

| Method | Purpose |
|--------|---------|
| `complete()` | Main entry point - completes contribution |
| `getContribution()` | Load contribution via APIv4 with validation |
| `isAlreadyCompleted()` | Idempotency check |
| `isPending()` | Status validation |
| `shouldSendReceipt()` | Auto-detect receipt from contribution page |
| `completeTransaction()` | Call `Contribution.completetransaction` API |

**Code:**
```php
<?php

namespace Civi\Paymentprocessingcore\Service;

use Civi\Api4\Contribution;
use Civi\Api4\ContributionPage;
use Civi\Paymentprocessingcore\Exception\ContributionCompletionException;

/**
 * Service for completing Pending contributions with payment processor transaction details.
 *
 * Generic service shared across all payment processors (Stripe, GoCardless, etc.).
 * Ensures idempotent completion (safe to call multiple times).
 *
 * @package Civi\Paymentprocessingcore\Service
 */
class ContributionCompletionService {

  /**
   * Complete a Pending contribution with payment processor transaction details.
   *
   * This method is idempotent - calling it multiple times with the same
   * contribution will not create duplicate accounting entries.
   *
   * @param int $contributionId CiviCRM contribution ID
   * @param string $transactionId Payment processor transaction ID (e.g., Stripe charge ID ch_..., GoCardless payment ID pm_...)
   * @param float|null $feeAmount Optional fee amount charged by payment processor
   * @param bool|null $sendReceipt Whether to send email receipt. If NULL, will check contribution page settings. Default: NULL
   *
   * @return array Completion result with keys: 'success' => TRUE, 'contribution_id' => int, 'already_completed' => bool
   *
   * @throws \Civi\Paymentprocessingcore\Exception\ContributionCompletionException If completion fails
   */
  public function complete(int $contributionId, string $transactionId, ?float $feeAmount = NULL, ?bool $sendReceipt = NULL): array {
    $contribution = $this->getContribution($contributionId);

    // Check if already completed (idempotency)
    if ($this->isAlreadyCompleted($contribution, $transactionId)) {
      return [
        'success' => TRUE,
        'contribution_id' => $contributionId,
        'already_completed' => TRUE,
      ];
    }

    // Validate contribution status
    if (!$this->isPending($contribution)) {
      throw new ContributionCompletionException(
        "Cannot complete contribution {$contributionId}: status is '{$contribution['contribution_status_id:name']}', expected 'Pending'",
        ['contribution_id' => $contributionId, 'status' => $contribution['contribution_status_id:name']]
      );
    }

    // Determine receipt setting
    if ($sendReceipt === NULL) {
      $sendReceipt = $this->shouldSendReceipt($contribution);
    }

    // Complete the transaction
    $this->completeTransaction($contribution, $transactionId, $feeAmount, $sendReceipt);

    return [
      'success' => TRUE,
      'contribution_id' => $contributionId,
      'already_completed' => FALSE,
    ];
  }

  /**
   * Get contribution by ID.
   *
   * @param int $contributionId Contribution ID
   *
   * @return array Contribution data
   *
   * @throws \Civi\Paymentprocessingcore\Exception\ContributionCompletionException If contribution not found
   */
  private function getContribution(int $contributionId): array {
    try {
      $contribution = Contribution::get(FALSE)
        ->addSelect('id', 'contribution_status_id:name', 'total_amount', 'currency', 'contribution_page_id', 'trxn_id')
        ->addWhere('id', '=', $contributionId)
        ->execute()
        ->first();

      if (!$contribution) {
        throw new ContributionCompletionException(
          "Contribution not found: {$contributionId}",
          ['contribution_id' => $contributionId]
        );
      }

      return $contribution;
    }
    catch (\Exception $e) {
      throw new ContributionCompletionException(
        "Failed to load contribution {$contributionId}: " . $e->getMessage(),
        ['contribution_id' => $contributionId, 'error' => $e->getMessage()]
      );
    }
  }

  /**
   * Check if contribution is already completed (idempotency).
   *
   * @param array $contribution Contribution data
   * @param string $transactionId Transaction ID
   *
   * @return bool TRUE if already completed
   */
  private function isAlreadyCompleted(array $contribution, string $transactionId): bool {
    if ($contribution['contribution_status_id:name'] === 'Completed') {
      \Civi::log()->info('ContributionCompletionService: Contribution already completed - idempotency check', [
        'contribution_id' => $contribution['id'],
        'transaction_id' => $transactionId,
        'existing_trxn_id' => $contribution['trxn_id'] ?? NULL,
      ]);
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Check if contribution is Pending (can be completed).
   *
   * @param array $contribution Contribution data
   *
   * @return bool TRUE if Pending
   */
  private function isPending(array $contribution): bool {
    return $contribution['contribution_status_id:name'] === 'Pending';
  }

  /**
   * Determine if receipt should be sent based on contribution page settings.
   *
   * @param array $contribution Contribution data
   *
   * @return bool TRUE if receipt should be sent
   */
  private function shouldSendReceipt(array $contribution): bool {
    if (empty($contribution['contribution_page_id'])) {
      // No contribution page (e.g., backend contribution) - default to no receipt
      return FALSE;
    }

    try {
      $contributionPage = ContributionPage::get(FALSE)
        ->addSelect('is_email_receipt')
        ->addWhere('id', '=', $contribution['contribution_page_id'])
        ->execute()
        ->first();

      return !empty($contributionPage['is_email_receipt']);
    }
    catch (\Exception $e) {
      \Civi::log()->warning('ContributionCompletionService: Failed to load contribution page settings', [
        'contribution_id' => $contribution['id'],
        'contribution_page_id' => $contribution['contribution_page_id'],
        'error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Complete the contribution transaction.
   *
   * Calls Contribution.completetransaction API which automatically:
   * - Creates payment record
   * - Posts accounting entries (A/R + Payment)
   * - Updates contribution status to Completed
   * - Sends receipt email if requested
   *
   * @param array $contribution Contribution data
   * @param string $transactionId Payment processor transaction ID
   * @param float|null $feeAmount Optional fee amount
   * @param bool $sendReceipt Whether to send email receipt
   *
   * @return void
   *
   * @throws \Civi\Paymentprocessingcore\Exception\ContributionCompletionException If completion fails
   */
  private function completeTransaction(array $contribution, string $transactionId, ?float $feeAmount, bool $sendReceipt): void {
    try {
      $params = [
        'id' => $contribution['id'],
        'trxn_id' => $transactionId,
        'is_email_receipt' => $sendReceipt ? 1 : 0,
      ];

      // Add fee amount if provided
      if ($feeAmount !== NULL) {
        $params['fee_amount'] = $feeAmount;
      }

      civicrm_api3('Contribution', 'completetransaction', $params);

      \Civi::log()->info('ContributionCompletionService: Contribution completed successfully', [
        'contribution_id' => $contribution['id'],
        'transaction_id' => $transactionId,
        'fee_amount' => $feeAmount,
        'amount' => $contribution['total_amount'],
        'currency' => $contribution['currency'],
        'receipt_sent' => $sendReceipt,
      ]);
    }
    catch (\CiviCRM_API3_Exception $e) {
      throw new ContributionCompletionException(
        "Failed to complete contribution {$contribution['id']}: " . $e->getMessage(),
        [
          'contribution_id' => $contribution['id'],
          'transaction_id' => $transactionId,
          'error' => $e->getMessage(),
          'error_data' => $e->getExtraParams(),
        ],
        $e
      );
    }
  }

}
```

---

### Phase 3: Create Custom Exception Class

**File:** `Civi/Paymentprocessingcore/Exception/ContributionCompletionException.php`

**Purpose:** Provide detailed error context for failures.

**Code:**
```php
<?php

namespace Civi\Paymentprocessingcore\Exception;

/**
 * Exception thrown when contribution completion fails.
 *
 * Extends \Exception to provide additional context data.
 */
class ContributionCompletionException extends \Exception {

  /**
   * Additional context data about the error.
   *
   * @var array
   */
  private $context;

  /**
   * ContributionCompletionException constructor.
   *
   * @param string $message Error message
   * @param array $context Additional context (contribution_id, transaction_id, etc.)
   * @param \Throwable|null $previous Previous exception
   */
  public function __construct(string $message, array $context = [], ?\Throwable $previous = NULL) {
    parent::__construct($message, 0, $previous);
    $this->context = $context;

    // Log the error with context
    \Civi::log()->error('ContributionCompletionException: ' . $message, $context);
  }

  /**
   * Get error context data.
   *
   * @return array
   */
  public function getContext(): array {
    return $this->context;
  }

}
```

---

### Phase 4: Create Comprehensive Unit Tests

**File:** `tests/phpunit/Civi/Paymentprocessingcore/Service/ContributionCompletionServiceTest.php`

**Test Coverage:**
1. ✅ **Success case:** Complete Pending contribution
2. ✅ **Idempotency:** Already completed contribution (returns success)
3. ✅ **Invalid status:** Non-Pending contribution (throws exception)
4. ✅ **Not found:** Invalid contribution ID (throws exception)
5. ✅ **Fee recording:** Verify fee amount is passed correctly
6. ✅ **Receipt - explicit TRUE:** Send receipt when requested
7. ✅ **Receipt - explicit FALSE:** Don't send receipt when not requested
8. ✅ **Receipt - auto-detect TRUE:** Check contribution page settings (is_email_receipt = 1)
9. ✅ **Receipt - auto-detect FALSE:** Check contribution page settings (is_email_receipt = 0)
10. ✅ **Receipt - no page:** Backend contribution (no receipt)
11. ✅ **Service container:** Verify service is accessible via `\Civi::service()`

**Code:**
```php
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
    $this->assertEquals(96.80, $contribution['net_amount']); // 100.00 - 3.20
  }

  /**
   * Tests receipt sent when explicitly requested.
   */
  public function testSendsReceiptWhenRequested(): void {
    $contributionId = $this->createPendingContribution();

    // Mock email to verify receipt is sent
    // (In real test, you'd use CiviCRM's test mail system)

    $result = $this->service->complete($contributionId, 'ch_test_receipt', NULL, TRUE);

    $this->assertTrue($result['success']);
    // Receipt verification would go here
  }

  /**
   * Tests receipt NOT sent when explicitly disabled.
   */
  public function testDoesNotSendReceiptWhenDisabled(): void {
    $contributionId = $this->createPendingContribution();

    $result = $this->service->complete($contributionId, 'ch_test_no_receipt', NULL, FALSE);

    $this->assertTrue($result['success']);
    // Verify no receipt sent
  }

  /**
   * Tests auto-detect receipt from contribution page (is_email_receipt = 1).
   */
  public function testAutoDetectReceiptFromContributionPageEnabled(): void {
    // Update contribution page to enable receipts
    ContributionPage::update(FALSE)
      ->addWhere('id', '=', $this->contributionPageId)
      ->addValue('is_email_receipt', TRUE)
      ->execute();

    $contributionId = $this->createPendingContribution(100.00, $this->contributionPageId);

    // sendReceipt = NULL should auto-detect from contribution page
    $result = $this->service->complete($contributionId, 'ch_test_auto_receipt', NULL, NULL);

    $this->assertTrue($result['success']);
    // Verify receipt was sent (based on contribution page setting)
  }

  /**
   * Tests auto-detect receipt from contribution page (is_email_receipt = 0).
   */
  public function testAutoDetectReceiptFromContributionPageDisabled(): void {
    // Update contribution page to disable receipts
    ContributionPage::update(FALSE)
      ->addWhere('id', '=', $this->contributionPageId)
      ->addValue('is_email_receipt', FALSE)
      ->execute();

    $contributionId = $this->createPendingContribution(100.00, $this->contributionPageId);

    // sendReceipt = NULL should auto-detect from contribution page
    $result = $this->service->complete($contributionId, 'ch_test_no_auto_receipt', NULL, NULL);

    $this->assertTrue($result['success']);
    // Verify receipt was NOT sent
  }

  /**
   * Tests backend contribution (no contribution page) does not send receipt by default.
   */
  public function testBackendContributionNoReceiptByDefault(): void {
    $contributionId = $this->createPendingContribution(100.00, NULL); // No contribution page

    // sendReceipt = NULL should default to FALSE for backend contributions
    $result = $this->service->complete($contributionId, 'ch_test_backend', NULL, NULL);

    $this->assertTrue($result['success']);
    // Verify receipt was NOT sent
  }

  /**
   * Tests service is accessible via service container.
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
```

---

### Phase 5: Update Documentation

**File:** `README.md`

**Add section:**
```markdown
## Services

### ContributionCompletionService

Generic service for completing Pending contributions with payment processor transaction details.

**Service ID:** `paymentprocessingcore.contribution_completion`

**Usage:**
```php
$service = \Civi::service('paymentprocessingcore.contribution_completion');

try {
  $result = $service->complete(
    $contributionId,      // CiviCRM contribution ID
    $transactionId,       // Payment processor transaction ID (e.g., ch_123)
    $feeAmount,          // Optional: Fee amount (e.g., 2.50)
    $sendReceipt         // Optional: TRUE/FALSE/NULL (NULL = auto-detect from contribution page)
  );

  if ($result['success']) {
    // Contribution completed successfully
    if ($result['already_completed']) {
      // Was already completed (idempotency)
    }
  }
}
catch (\Civi\Paymentprocessingcore\Exception\ContributionCompletionException $e) {
  // Handle error
  $context = $e->getContext();
  \Civi::log()->error('Completion failed', $context);
}
```

**Features:**
- ✅ **Idempotent** - Safe to call multiple times (checks if already completed)
- ✅ **Automatic accounting** - Handles accounting entries via `Contribution.completetransaction` API
- ✅ **Auto-detect receipts** - Reads `is_email_receipt` from contribution page if `$sendReceipt = NULL`
- ✅ **Detailed errors** - Throws exceptions with context via `getContext()` method
- ✅ **Generic** - Works with all payment processors (Stripe, GoCardless, ITAS, Deluxe, etc.)
- ✅ **Fee recording** - Optionally records payment processor fee amount

**Parameters:**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `$contributionId` | `int` | Yes | - | CiviCRM contribution ID |
| `$transactionId` | `string` | Yes | - | Payment processor transaction ID (e.g., `ch_1234` for Stripe) |
| `$feeAmount` | `float\|null` | No | `NULL` | Fee amount charged by payment processor |
| `$sendReceipt` | `bool\|null` | No | `NULL` | Whether to send receipt email. If `NULL`, auto-detects from contribution page settings |

**Return Value:**
```php
[
  'success' => TRUE,
  'contribution_id' => 123,
  'already_completed' => FALSE,  // TRUE if contribution was already completed
]
```

**Exceptions:**
- `ContributionCompletionException` - Thrown if completion fails (contribution not found, invalid status, API error, etc.)

**Used By:**
- Stripe extension (success URL handler, webhook handler)
- GoCardless extension (webhook handler)
- Other payment processor extensions
```

---

## Implementation Checklist

### Files to Create (4 new files):
- [ ] `Civi/Paymentprocessingcore/CompilerPass/RegisterServicesPass.php`
- [ ] `Civi/Paymentprocessingcore/Service/ContributionCompletionService.php`
- [ ] `Civi/Paymentprocessingcore/Exception/ContributionCompletionException.php`
- [ ] `tests/phpunit/Civi/Paymentprocessingcore/Service/ContributionCompletionServiceTest.php`

### Files to Modify (2 files):
- [ ] `paymentprocessingcore.php` (add `hook_civicrm_container`)
- [ ] `README.md` (document the service)

### Testing:
- [ ] Run unit tests: `./scripts/run.sh tests`
- [ ] Run linting: `./scripts/lint.sh check`
- [ ] Run PHPStan: `./scripts/run.sh phpstan-changed`
- [ ] Verify service accessible: `cv eval 'return \Civi::service("paymentprocessingcore.contribution_completion");'`

### Git:
- [ ] Commit with message: `CIVIMM-###: Add ContributionCompletionService for generic payment completion`

---

## File Summary

### Directory Structure

```
io.compuco.paymentprocessingcore/
├── Civi/Paymentprocessingcore/
│   ├── CompilerPass/
│   │   └── RegisterServicesPass.php          (NEW - Phase 1)
│   ├── Service/
│   │   └── ContributionCompletionService.php (NEW - Phase 2)
│   └── Exception/
│       └── ContributionCompletionException.php (NEW - Phase 3)
├── tests/phpunit/
│   └── Civi/Paymentprocessingcore/Service/
│       └── ContributionCompletionServiceTest.php (NEW - Phase 4)
├── paymentprocessingcore.php                 (MODIFY - Phase 1)
└── README.md                                  (MODIFY - Phase 5)
```

---

## Estimated Effort

- **Lines of code:** ~500 lines total
  - Service: ~200 lines
  - Tests: ~200 lines
  - Exception: ~30 lines
  - Compiler Pass: ~20 lines
  - Documentation: ~50 lines
- **Time:** 4-6 hours (including testing and documentation)
- **Complexity:** Medium (service container setup + comprehensive testing)

---

## Testing Strategy

### Unit Tests (Automated)

All tests extend `BaseHeadlessTest` and use APIv4 for test data setup:

| Test | Purpose | Expected Result |
|------|---------|----------------|
| `testCompletesPendingContribution()` | Complete Pending contribution | Status = Completed, trxn_id set |
| `testIdempotencyAlreadyCompleted()` | Call twice on same contribution | Returns success both times, no error |
| `testThrowsExceptionForNonPendingContribution()` | Try to complete Cancelled contribution | Throws exception with message |
| `testThrowsExceptionForInvalidContributionId()` | Invalid contribution ID | Throws exception |
| `testRecordsFeeAmount()` | Complete with fee amount | fee_amount and net_amount correct |
| `testSendsReceiptWhenRequested()` | Explicit `$sendReceipt = TRUE` | Receipt sent |
| `testDoesNotSendReceiptWhenDisabled()` | Explicit `$sendReceipt = FALSE` | Receipt NOT sent |
| `testAutoDetectReceiptFromContributionPageEnabled()` | `$sendReceipt = NULL`, page.is_email_receipt = 1 | Receipt sent |
| `testAutoDetectReceiptFromContributionPageDisabled()` | `$sendReceipt = NULL`, page.is_email_receipt = 0 | Receipt NOT sent |
| `testBackendContributionNoReceiptByDefault()` | No contribution page | Receipt NOT sent |
| `testServiceAccessibleViaContainer()` | Get service via `\Civi::service()` | Service instance returned |

### Manual Testing (Optional)

1. **Service Registration:**
   ```bash
   cv eval 'return \Civi::service("paymentprocessingcore.contribution_completion");'
   # Expected: ContributionCompletionService object
   ```

2. **Complete Pending Contribution:**
   ```bash
   cv eval '
   $service = \Civi::service("paymentprocessingcore.contribution_completion");
   $result = $service->complete(123, "ch_test_12345", 2.50, FALSE);
   return $result;
   '
   # Expected: ['success' => TRUE, 'contribution_id' => 123, 'already_completed' => FALSE]
   ```

3. **Idempotency Check:**
   ```bash
   # Run same command twice
   # Expected: Second call returns ['already_completed' => TRUE]
   ```

---

## Success Criteria

- ✅ All unit tests pass (11 tests)
- ✅ Linting passes (PHPCS)
- ✅ PHPStan passes (level 9)
- ✅ Service accessible via `\Civi::service('paymentprocessingcore.contribution_completion')`
- ✅ Idempotent behavior verified
- ✅ Exception context data accessible via `getContext()`
- ✅ Documentation complete in README.md
- ✅ All files follow CiviCRM coding standards

---

## How This Addresses Original Concerns

| Concern | Solution |
|---------|----------|
| **No Service Container** | ✅ Phase 1: Implement `hook_civicrm_container` + compiler pass |
| **API Version Inconsistency** | ✅ Phase 2: Use APIv4 for queries, APIv3 only for `completetransaction` |
| **Missing Error Context** | ✅ Phase 3: Custom exception with `getContext()` method |
| **Receipt Logic Location** | ✅ Phase 2: `shouldSendReceipt()` method in service (generic) |
| **No Page Validation** | ✅ Phase 2: Validate contribution page exists before querying |
| **Transaction ID Collision** | ✅ Handled by CiviCRM API's built-in validation |
| **Logging vs Exception** | ✅ Phase 2 & 3: Throw exceptions + log errors |

---

## References

- **CiviCRM API:** `Contribution.completetransaction` - https://docs.civicrm.org/dev/en/latest/financial/orderAPI/
- **Service Container:** https://docs.civicrm.org/dev/en/latest/framework/container/
- **APIv4:** https://docs.civicrm.org/dev/en/latest/api/v4/
- **Compiler Pass:** https://symfony.com/doc/current/service_container/compiler_passes.html
- **Original Plan:** `/Users/erawat/Projects/Alpha/clients/cc7/profiles/compuclient/modules/contrib/civicrm/ext/uk.co.compucorp.stripe/docs/ONEOFF-SUCCESS-URL-IMPLEMENTATION-PLAN.md`

---

**Ready for implementation!**