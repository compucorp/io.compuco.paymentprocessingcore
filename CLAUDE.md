<!-- CLAUDE.md v1.0 | Last updated: 2025-01-20 -->

# 🧠 Claude Code Development Guide

This file defines how **Claude Code (Anthropic)** should be used within this project.
It acts as both:
- a **developer onboarding guide**, and
- a **context reference** for Claude when assisting in coding tasks.

Claude Code can edit files, plan changes, and run commands — but must follow all internal development standards described here.

---

## 📦 Project Overview

This is a **CiviCRM extension** that provides **generic payment processing infrastructure** for multiple payment processors (Stripe, GoCardless, ITAS, Deluxe, etc.). It centralizes common payment logic that was previously duplicated across processor-specific extensions.

**Extension Key:** `io.compuco.paymentprocessingcore`
**Repository:** https://github.com/compucorp/io.compuco.paymentprocessingcore
**CiviCRM Version:** 6.64.1+ (CI), 6.4.1+ (Development)
**PHP Version:** 7.3+ (minimum), 8.0+ (recommended)

**Core Purpose:**
- Provide generic payment attempt tracking (`civicrm_payment_attempt`)
- Provide generic webhook event deduplication (`civicrm_payment_webhooks`)
- Centralize contribution completion/failure/cancellation logic
- Define interfaces for payment processors to implement
- Reduce code duplication across payment processors

**Dependencies:**
- CiviCRM Core 6.64.1+
- PHP 8.1+

**Who Uses This Extension:**
This extension is used by payment processor extensions:
- `uk.co.compucorp.stripe` - Stripe Connect integration
- `uk.co.compuco.gocardless` - GoCardless integration (future)
- Other payment processors (future)

**Installation:**
```bash
# Install PaymentProcessingCore
composer install
cv en paymentprocessingcore

# OR with Drush:
drush cei paymentprocessingcore
```

**Important:** Payment processor extensions depend on this extension for generic payment processing infrastructure.

---

## 0. Development Environment Setup

### CiviCRM Core Reference

For development and debugging, it's highly recommended to have CiviCRM core code available for reference. This helps with:
- Understanding CiviCRM API changes between versions
- Debugging core integration issues
- Verifying compatibility with core expectations
- Checking for known issues and patches

**Setup:**
```bash
# Clone Compucorp's CiviCRM core fork (includes patches for 5.75.0)
# From the extension root directory:
git clone https://github.com/compucorp/civicrm-core.git
cd civicrm-core

# Checkout the patches branch used by Compucorp
git checkout 6.4.1-patches
cd ..

# The core will be at: ./civicrm-core/
# (inside the extension directory: io.compuco.paymentprocessingcore/civicrm-core/)
```

**Note:** We use Compucorp's fork of CiviCRM core rather than the official repository because it includes necessary patches applied via the `compucorp/apply-patch` GitHub Action in CI workflows.

**Important:** Add `civicrm-core/` to `.gitignore` if not already present, as this is a reference copy for development only.

**Usage:**
- Reference core API implementations when debugging
- Check core changes when updating CiviCRM version
- Verify parameter requirements for API calls
- Look for patches that may be needed

**Note for Claude Code:** When working on compatibility issues or API integration, always check the CiviCRM core code at `./civicrm-core/` if available. This will help identify breaking changes and required parameter updates.

---

## 1. Pull Request Descriptions

All PRs must use the standard template stored at `.github/PULL_REQUEST_TEMPLATE.md`.

Claude can help generate PR descriptions but must follow this structure:

**Required Sections:**
- **Overview**: Non-technical description of the change
- **Before**: Current status with screenshots/gifs where appropriate
- **After**: What changed with screenshots/gifs where appropriate
- **Technical Details**: Noteworthy technical changes, code snippets
- **Core overrides**: If any CiviCRM core files are patched (file, reason, changes)
- **Comments**: Any additional notes for reviewers

**When drafting PRs:**
- Reference the ticket ID in the PR title, e.g. `CIVIMM-123: Add webhook event deduplication`
- Fill all required template sections
- Keep summaries factual — avoid assumptions
- Include before/after screenshots for UI changes

**Example Claude prompt:**
> Summarize the following diff into a PR description using `.github/PULL_REQUEST_TEMPLATE.md`.
> Include the issue key `CIVIMM-123` and explain what changed, why, and how to test.

---

## 1.5. Handling Pull Request Review Feedback

When receiving PR review comments (from GitHub, Copilot, or human reviewers), **NEVER blindly implement feedback**. Always think critically and ask questions.

**Required Process:**

1. **Analyze Each Suggestion:**
   - Does this suggestion make technical sense?
   - What are the implications (database constraints, type safety, performance)?
   - Could this break existing functionality?
   - Is this consistent with the project's architecture?

2. **Ask Clarifying Questions:**
   - If unsure about the reasoning, ask the user: "Why is this change recommended?"
   - If there are trade-offs, present them: "This suggestion would fix X but might break Y - which is preferred?"
   - If the suggestion seems incorrect, explain why: "I think this might cause issues because..."

3. **Explain Your Analysis:**
   - For each change, explain WHY you're making it (or not making it)
   - Present technical reasoning (e.g., "is_null() is more precise than empty() for integer IDs because...")
   - Highlight potential issues (e.g., "Making contact_id NOT NULL would prevent ON DELETE SET NULL from working")

4. **Get Approval Before Implementing:**
   - Show the user what you plan to change
   - Wait for explicit confirmation before committing
   - Never batch commit multiple review changes without review

**Important for Claude Code:**

- ✅ Always explain your reasoning for accepting or rejecting feedback
- ✅ Present trade-offs clearly to the user
- ✅ Ask for clarification when suggestions seem wrong
- ⚠️ Never commit without user approval
- ⚠️ Don't assume reviewers are always correct - they can be wrong too
- ✅ Your job is to provide technical analysis, not blindly follow instructions

---

## 2. Unit Testing

Unit tests are **mandatory** for all new features and bug fixes.

**Requirements:**
- Tests must be written using **PHPUnit** (CiviCRM extension standard)
- Store tests in `tests/phpunit/` directory, mirroring source structure
- Tests require full CiviCRM buildkit environment
- Never modify or skip tests just to make them pass. Fix the underlying code.

**Running Tests Locally with Docker (Recommended):**

This project includes a flexible Docker-based test environment with configurable CiviCRM versions:

**Configuration** (`scripts/env-config.sh`):
- **Default**: CiviCRM 6.4.1, Drupal 7.100 (current development target)

```bash
# Setup with default CiviCRM version (6.4.1)
./scripts/run.sh setup

# Run all tests
./scripts/run.sh tests

# Run specific test file
./scripts/run.sh test tests/phpunit/Civi/PaymentProcessingCore/Service/PaymentAttemptServiceTest.php

# Generate DAO files from XML schemas
./scripts/run.sh civix

# Open shell in CiviCRM container
./scripts/run.sh shell

# Run cv commands
./scripts/run.sh cv cli
./scripts/run.sh cv api Contact.get

# Stop services (preserves data)
./scripts/run.sh stop

# Clean up (removes all data including volumes)
./scripts/run.sh clean
```

**Typical Workflow:**
```bash
# 1. Setup test environment and run tests (most common)
./scripts/run.sh setup
./scripts/run.sh tests

# 2. When schema changes, regenerate DAO files
# (Only needed when xml/schema/ files are modified)
./scripts/run.sh civix                         # Regenerates DAO files
./scripts/run.sh tests                         # Verify tests still pass
```

**What the setup does:**
- Spins up MySQL 8.0 service
- Builds Drupal 7.100 site with specified CiviCRM version using civibuild
- Configures CiviCRM settings
- Creates test database
- Enables the extension

**Important Notes:**
- The `civix` command uses rsync to properly sync generated files from container to host
- Always use `./scripts/run.sh clean` before switching CiviCRM versions
- SQL files (`sql/auto_install.sql`, `sql/auto_uninstall.sql`) are auto-generated by civix - do not edit manually

**Or trigger CI workflow locally using act:**
```bash
act -j run-unit-tests -P ubuntu-latest=compucorp/civicrm-buildkit:1.3.1-php8.0
```

**Running Tests Without Docker:**
```bash
# Run all tests
vendor/bin/phpunit

# Run specific test file
vendor/bin/phpunit tests/phpunit/Civi/PaymentProcessingCore/Service/PaymentAttemptServiceTest.php

# Run specific test method
vendor/bin/phpunit --filter testCreatePaymentAttempt tests/phpunit/Civi/PaymentProcessingCore/Service/PaymentAttemptServiceTest.php
```

**CI Workflow:**
Tests run automatically on PRs via `.github/workflows/unit-test.yml` which:
- Sets up MariaDB 10.5 service container
- Configures CiviCRM database requirements
- Builds Drupal 7.100 site with CiviCRM 6.4.1 using civibuild
- Installs required extensions and dependencies
- Runs PHPUnit tests with coverage

**Test Patterns:**
- Extend `BaseHeadlessTest` for all test classes
- Use fabricators in `tests/phpunit/Fabricator/` to create test data
- Test positive, negative, and edge cases
- Mock external API calls when appropriate

**Example Claude prompt:**
> Generate a PHPUnit test for `Civi\PaymentProcessingCore\Service\PaymentAttemptService::createAttempt()`.
> Cover success case, API error case, and missing parameter case using existing test patterns.

**Important for Claude Code:**
- ⚠️ Cannot run tests directly without Docker/buildkit environment
- ✅ Can write test files following existing patterns
- ✅ Can review test output from CI workflows
- ✅ Suggest: "Push changes to trigger CI tests" or "Run tests via Docker"

All tests must pass before commits are pushed or PRs are opened.

---

## 3. Code Linting & Style

Code must follow **CiviCRM Drupal coding standards** and pass all linting checks.

**Ruleset:** Custom ruleset defined in `phpcs-ruleset.xml` (based on Drupal standards)
- Excludes `paymentprocessingcore.civix.php` (auto-generated file)

**Running Linters Locally (Docker - Recommended):**
```bash
# Run linter on changed files (vs origin/master)
./scripts/lint.sh check

# Auto-fix linting issues
./scripts/lint.sh fix

# Run linter on all source files
./scripts/lint.sh check-all

# Stop linter container
./scripts/lint.sh stop
```

**Running Linters Locally (Manual):**
```bash
# Install linter (if needed)
cd bin && ./install-php-linter

# Run linter on changed files (used by CI)
git diff --diff-filter=d origin/master --name-only -- '*.php' | xargs -r ./bin/phpcs.phar --standard=phpcs-ruleset.xml

# Or lint all PHP files
./bin/phpcs.phar --standard=phpcs-ruleset.xml CRM/ Civi/ api/

# Auto-fix fixable issues
./bin/phpcbf.phar --standard=phpcs-ruleset.xml CRM/ Civi/ api/
```

**CI Workflow:**
Linting runs automatically via `.github/workflows/linters.yml` on all PHP files changed in the PR.

**Important for Claude Code:**
- ✅ Can fix style issues based on linter output
- ✅ Can apply Drupal coding standards
- ⚠️ Always check formatting before commits
- ✅ Suggest: "Run linter to check code style"

### File Newline Requirements

**All files must end with a newline character** (POSIX standard compliance).

**Why this matters:**
- Git diffs show "No newline at end of file" warnings for files without newlines
- Many Unix tools expect files to end with newlines
- POSIX defines a line as ending with a newline character
- Prevents potential issues with concatenation and shell scripts

**Important for Claude Code:**
- ✅ Always ensure files end with newlines when creating or editing
- ✅ Can check for missing newlines before commits
- ⚠️ Editor settings should be configured to add trailing newlines automatically
- ✅ Verify with `git diff --check` before pushing

---

## 3.5. Static Analysis (PHPStan)

All code must pass **PHPStan level 9** static analysis, the strictest PHP type checking available.

**Configuration:** `phpstan.neon` - Configured for Docker test environment
- **Level:** 9 (maximum strictness)
- **Baseline:** `phpstan-baseline.neon` - Contains ignored errors from existing code
- **Approach:** Baseline captures existing errors, enforces strict typing on all future code

**What Gets Analyzed:**
- All source files in `Civi/` and `CRM/` directories
- Test files (important for quality!)
- New untracked files

**What Gets Excluded (Auto-Generated):**
- `CRM/PaymentProcessingCore/DAO/*` - Generated by civix from XML schemas
- `paymentprocessingcore.civix.php` - Generated by civix
- `*.mgd.php` - CiviCRM managed entity files
- `tests/bootstrap.php` - Test bootstrap configuration

**Running PHPStan Locally (Docker - Recommended):**
```bash
# Run PHPStan on changed files only (recommended - fast)
./scripts/run.sh phpstan-changed

# Run PHPStan on entire codebase (slow - full analysis)
./scripts/run.sh phpstan
```

**Prerequisites:**
- Docker environment must be running: `./scripts/run.sh setup`
- PHPStan needs access to CiviCRM core for type information

**CI Workflow:**
PHPStan runs automatically via `.github/workflows/phpstan.yml` on all changed PHP files in the PR.

**Important for Claude Code:**
- ✅ Can read PHPStan errors and suggest fixes
- ✅ Can add proper type hints to fix errors
- ⚠️ Always run `./scripts/run.sh phpstan-changed` before pushing
- ⚠️ Never regenerate baseline to "fix" errors - fix the code instead
- ✅ Suggest: "Run PHPStan to check type safety"

---

## 4. 🛡️ Critical Review Areas

### 🔐 Security

**Payment Processing Security:**
- Never log or expose sensitive payment data
- Validate all payment amounts and currency codes before processing
- Check for SQL injection in dynamic queries (use parameterized queries)
- Sanitize all user input before rendering (XSS prevention)
- Verify webhook signatures for payment processors
- Ensure proper authentication/authorization for API endpoints

**Sensitive Data Handling:**
- Payment processor IDs, transaction IDs are sensitive
- All payment processor API calls should use proper error handling
- Payment processor credentials stored in `civicrm.settings.php` must never be committed

### 🚀 Performance

- Identify N+1 query issues in contribution/contact lookups
- Detect inefficient loops when processing bulk payments
- Avoid unnecessary API calls (use cached records)
- Review database queries in BAO classes for optimization

### 🧼 Code Quality

- Services should be focused and follow single responsibility principle
- Use meaningful names following CiviCRM conventions (`CRM_*` or `Civi\*`)
- Handle exceptions properly (use custom exception classes)
- All service methods should have proper return type declarations
- Use dependency injection for service dependencies

---

## 5. Commit Message Convention

All commits must start with the branch prefix (issue ID) followed by a short imperative description.

**Format:**
```
CIVIMM-123: Short description of change
```

**Rules:**
- Keep summaries under 72 characters
- Use present tense ("Add", "Fix", "Refactor")
- Claude must include the correct issue key when committing
- Be specific and descriptive
- **DO NOT add any AI attribution or co-authorship lines** (no "Generated with Claude Code", no "Co-Authored-By: Claude")

**Examples:**
```
CIVIMM-456: Add PaymentAttempt entity with multi-processor support
CIVIMM-789: Implement webhook event deduplication service
CIVIMM-101: Refactor ContributionCompletionService for idempotency
```

If Claude proposes commits automatically, it must use this exact format without any attribution footer.

---

## 6. Continuous Integration (CI)

All code must pass these workflows before merging:

| Workflow | Purpose | Local Command | CI File |
|-----------|----------|---------------|---------|
| **unit-test.yml** | PHPUnit test execution | `./scripts/run.sh tests` | `.github/workflows/unit-test.yml` |
| **linters.yml** | Code style and lint checks (PHPCS) | `./scripts/lint.sh check` | `.github/workflows/linters.yml` |
| **phpstan.yml** | Static analysis (PHPStan level 9) | `./scripts/run.sh phpstan-changed` | `.github/workflows/phpstan.yml` |

Claude must ensure that code:
- ✅ Passes **PHPUnit tests** (no test failures)
- ✅ Passes **linting** (CiviCRM Drupal standard compliance)
- ✅ Passes **PHPStan** (level 9 static analysis on changed files)

---

## 7. Architecture

### Code Organization

The extension uses two primary namespaces:

1. **`CRM_*` namespace** (CRM/ directory): Traditional CiviCRM architecture
   - **DAO/**: Database Access Objects (auto-generated from XML schemas in `xml/schema/`)
   - **BAO/**: Business Access Objects extending DAOs with business logic
   - **Page/**: Base classes for webhook endpoints

2. **`Civi\PaymentProcessingCore\*` namespace** (Civi/ directory): Modern service-oriented architecture
   - **Service/**: Business logic services (payment attempts, webhook logging, completion, failure)
   - **Utils/**: Utility classes for common operations
   - **Interface/**: Contracts for payment processors to implement
   - **Hook/**: Hook implementations (Container)

### Core Purpose: Centralization

This extension centralizes payment processing logic that was previously duplicated across payment processors:

**What's Centralized (Generic across all processors):**
- **PaymentAttempt tracking** - Generic table for all processors (`civicrm_payment_attempt`)
- **Webhook event deduplication** - Generic table (`civicrm_payment_webhooks`)
- **ContributionCompletionService** - Generic contribution completion logic
- **ContributionFailureService** - Generic status transitions (Pending → Cancelled → Failed)
- **ContributionCancellationService** - Generic cancellation logic
- **WebhookEventLogService** - Generic webhook de-duplication
- **WebhookBase** - Abstract base class for webhook endpoints

**What Stays in Processor Extensions (Processor-specific):**
- **Processor API calls** - Stripe Checkout, GoCardless Mandate, etc.
- **Webhook signature verification** - Processor-specific cryptography
- **Webhook event parsing** - Processor-specific schemas
- **Fee calculation** - Processor-specific fee structures

### Key Entities

The extension manages two custom database entities defined in `xml/schema/CRM/PaymentProcessingCore/`:

- **PaymentAttempt**: Generic payment attempt tracking for all processors
- **PaymentWebhook**: Generic webhook event logging for deduplication

### Service Layer Architecture

Services are the primary business logic layer, registered via dependency injection in `Civi\PaymentProcessingCore\Hook\Container\ServiceContainer`.

**Core Services:**
- **PaymentAttemptService**: CRUD operations for payment attempts
- **WebhookEventLogService**: Webhook event deduplication
- **ContributionCompletionService**: Generic contribution completion
- **ContributionFailureService**: Generic contribution failure handling
- **ContributionCancellationService**: Generic contribution cancellation
- **PaymentStatusMapper**: Maps processor statuses to CiviCRM statuses

### Interfaces

The extension defines interfaces that payment processors must implement:

- **WebhookHandlerInterface**: Contract for webhook event handlers (processor-specific)
- **PaymentAttemptInterface**: Contract for attempt handling
- **PaymentSessionInterface**: Contract for session handling

### Webhook Processing System

The extension provides a queue-based webhook processing system with automatic retry and recovery:

**Architecture:**
```
Webhook arrives → Verify signature → Save to DB → Queue → Process → Complete/Retry
```

**Key Components:**
1. **WebhookHandlerInterface** - Contract all handlers must implement
2. **WebhookHandlerRegistry** - Central registry mapping event types to handlers
3. **WebhookQueueService** - Per-processor SQL queues for webhook tasks
4. **WebhookQueueRunnerService** - Processes queued tasks with retry logic
5. **PaymentWebhook entity** - Generic webhook storage across all processors

**How Processor Extensions Integrate:**

```php
// 1. Create webhook endpoint page (processor-specific)
class CRM_YourProcessor_Page_Webhook extends CRM_Core_Page {
  public function run(): void {
    $payload = file_get_contents('php://input');
    $signature = $_SERVER['HTTP_YOUR_PROCESSOR_SIGNATURE'] ?? '';

    /** @var \Civi\YourProcessor\Service\WebhookReceiverService $receiver */
    $receiver = \Civi::service('yourprocessor.webhook_receiver');
    $receiver->handleRequest($payload, $signature);

    CRM_Utils_System::civiExit();
  }
}

// 2. Create webhook receiver service (processor-specific)
namespace Civi\YourProcessor\Service;

use Civi\Paymentprocessingcore\Service\WebhookQueueService;

class WebhookReceiverService {
  private WebhookQueueService $queueService;

  public function __construct(WebhookQueueService $queueService) {
    $this->queueService = $queueService;
  }

  public function handleRequest(string $payload, string $signature): void {
    // 1. Verify signature (processor-specific cryptography)
    $event = $this->verifyAndParseEvent($payload, $signature);

    // 2. Check if event type should be processed
    if (!in_array($event->type, self::ALLOWED_EVENTS, TRUE)) {
      http_response_code(200);
      return;
    }

    // 3. Save to generic webhook table (atomic insert to prevent duplicates)
    $webhookId = $this->saveWebhookEventAtomic($event);

    if ($webhookId === NULL) {
      // Duplicate - already processed
      http_response_code(200);
      return;
    }

    // 4. Add to queue (generic queue service)
    $this->queueService->addTask(
      'yourprocessor',  // processor type
      $webhookId,
      ['event_data' => $event->toArray()]
    );

    http_response_code(200);
  }

  private function saveWebhookEventAtomic($event): ?int {
    // Use INSERT IGNORE for atomic duplicate prevention
    $sql = "INSERT IGNORE INTO civicrm_payment_webhook
            (event_id, processor_type, event_type, status, attempts, created_date)
            VALUES (%1, %2, %3, 'new', 0, NOW())";

    \CRM_Core_DAO::executeQuery($sql, [
      1 => [$event->id, 'String'],
      2 => ['yourprocessor', 'String'],
      3 => [$event->type, 'String'],
    ]);

    $affectedRows = \CRM_Core_DAO::singleValueQuery("SELECT ROW_COUNT()");
    return ($affectedRows > 0) ? (int) \CRM_Core_DAO::singleValueQuery("SELECT LAST_INSERT_ID()") : NULL;
  }
}

// 3. Create event handlers (processor-specific business logic)
namespace Civi\YourProcessor\Webhook;

use Civi\Paymentprocessingcore\Webhook\WebhookHandlerInterface;
use Civi\Paymentprocessingcore\Service\ContributionCompletionService;

class PaymentSuccessHandler implements WebhookHandlerInterface {
  private ContributionCompletionService $completionService;

  public function __construct(ContributionCompletionService $completionService) {
    $this->completionService = $completionService;
  }

  public function handle(int $webhookId, array $params): string {
    $eventData = $params['event_data'];
    $payment = $eventData['data']['object'];

    // Find payment attempt
    $attempt = \Civi\Api4\PaymentAttempt::get(FALSE)
      ->addWhere('processor_payment_id', '=', $payment['id'])
      ->addWhere('processor_type', '=', 'yourprocessor')
      ->execute()
      ->first();

    if (!$attempt) {
      return 'noop';
    }

    // Complete contribution
    try {
      $result = $this->completionService->complete(
        $attempt['contribution_id'],
        $payment['id'],
        $payment['fee'] ?? NULL
      );

      return $result['already_completed'] ? 'noop' : 'applied';
    }
    catch (\Exception $e) {
      \Civi::log()->error('Payment completion failed', [
        'webhook_id' => $webhookId,
        'error' => $e->getMessage(),
      ]);
      throw $e;
    }
  }
}

// 4. Register services and handlers in ServiceContainer.php
namespace Civi\YourProcessor\Hook\Container;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class ServiceContainer {
  public function register(): void {
    // Check if PaymentProcessingCore is available (defensive)
    if (!$this->container->has('paymentprocessingcore.webhook_queue')) {
      return;
    }

    // Register webhook receiver service
    $this->container->setDefinition('yourprocessor.webhook_receiver', new Definition(
      \Civi\YourProcessor\Service\WebhookReceiverService::class,
      [new Reference('paymentprocessingcore.webhook_queue')]
    ))->setPublic(TRUE);

    // Register event handlers
    $this->container->setDefinition('yourprocessor.handler.payment_success', new Definition(
      \Civi\YourProcessor\Webhook\PaymentSuccessHandler::class
    ))->setAutowired(TRUE)->setPublic(TRUE);

    $this->container->setDefinition('yourprocessor.handler.payment_failed', new Definition(
      \Civi\YourProcessor\Webhook\PaymentFailedHandler::class
    ))->setAutowired(TRUE)->setPublic(TRUE);

    // Register handlers with PaymentProcessingCore registry (compile-time)
    $this->registerWebhookHandlers();
  }

  private function registerWebhookHandlers(): void {
    if (!$this->container->hasDefinition('paymentprocessingcore.webhook_handler_registry')) {
      return;
    }

    $registry = $this->container->getDefinition('paymentprocessingcore.webhook_handler_registry');

    // Register handler for payment.success event
    $registry->addMethodCall('registerHandler', [
      'yourprocessor',           // processor type
      'payment.success',         // event type
      'yourprocessor.handler.payment_success',  // handler service ID
    ]);

    // Register handler for payment.failed event
    $registry->addMethodCall('registerHandler', [
      'yourprocessor',
      'payment.failed',
      'yourprocessor.handler.payment_failed',
    ]);
  }
}
```

**How Auto-Discovery Works:**

1. Container compilation phase:
   - PaymentProcessingCore creates empty WebhookHandlerRegistry
   - Each processor extension calls `registry->addMethodCall('registerHandler', ...)`
   - Registry is populated before any code runs

2. Scheduled job runs (processor_type='all'):
   - Calls `WebhookQueueRunnerService->runAllQueues()`
   - Registry returns all processor types: `['stripe', 'yourprocessor', 'gocardless']`
   - Automatically processes webhooks from ALL registered processors

3. Adding a new processor:
   - Install extension
   - Container rebuilds
   - New processor auto-appears in processing queue
   - **No configuration changes needed!**

**Retry & Recovery:**
- Exponential backoff: 5min → 15min → 45min
- Max 3 attempts before marking as permanent_error
- Stuck webhook recovery (resets webhooks stuck in "processing" > 30 minutes)
- Batch processing to prevent job timeouts (250 events per processor per run)

---

## 8. Workflow with Claude Code

Claude Code operates in **Plan Mode** and **Execution Mode**.

**Recommended Flow:**
1. **Explain** – Ask Claude to describe the issue in its own words
2. **Plan** – Enable Plan Mode (`Shift + Tab` twice) and ask for a clear step-by-step fix plan
3. **Review** – Verify and edit Claude's plan before implementation
4. **Implement** – Disable Plan Mode and let Claude apply changes
5. **Verify** – Run linting and tests to confirm all checks pass

**Safe Commands:**
```bash
# Check git status and diff
git status
git diff

# Run linting
./scripts/lint.sh check

# Run tests (requires Docker/buildkit)
./scripts/run.sh tests

# Commit changes
git commit -m "CIVIMM-###: ..."
```

**Request Confirmation Before:**
- Deleting or overwriting files
- Running migrations or database changes
- Modifying auto-generated files (`paymentprocessingcore.civix.php`, DAO files)
- Making changes to `xml/schema/` files (require regeneration)

---

## 9. Review & Validation

After Claude proposes code:

1. Review the diff manually
2. Run linting and tests
3. Ensure commit message format is correct (CIVIMM-###: ...)
4. Push the branch and open a PR using the PR template
5. Verify CI passes (unit-test.yml, linters.yml, phpstan.yml)

If Claude generates documentation or summaries, review for accuracy before committing.

---

## 10. Developer Prompts (Examples)

| Task | Example Prompt |
|------|----------------|
| Generate tests | "Create PHPUnit tests for `PaymentAttemptService::createAttempt()` covering success, API error, and invalid parameter cases." |
| Summarize PR | "Summarize the last 3 commits into a PR description using `.github/PULL_REQUEST_TEMPLATE.md` for issue CIVIMM-123." |
| Fix linting | "PHPCS reports style violations in `ContributionCompletionService.php`. Fix all issues according to `phpcs-ruleset.xml`." |
| Refactor | "Refactor `WebhookEventLogService` to improve testability, preserving all logic and tests." |
| Add service | "Create a new service `RefundService` following the existing service patterns with dependency injection." |
| Update docs | "Add PHPDoc blocks to all public methods in `PaymentAttemptService` with proper type hints." |

---

## 11. Common Patterns & Best Practices

**Service Registration:**
Services registered in `Civi\PaymentProcessingCore\Hook\Container\ServiceContainer` using Symfony DI container.

**Exception Handling:**
Use domain-specific exceptions from `Civi\PaymentProcessingCore\Exception/`:
- `PaymentAttemptException`: Payment attempt errors
- `WebhookException`: Webhook processing errors

**Logging:**
Always use `Civi\PaymentProcessingCore\Utils\Logger` for consistent logging:
```php
$logger = Civi::service('service.logger');
$logger->log('Payment attempt created', ['attempt_id' => $attemptId]);
```

**Database Schema Changes:**
When modifying entities in `xml/schema/`, regenerate DAO files:
```bash
./scripts/run.sh civix
```

---

## 12. Safety & Best Practices

**CRITICAL: Always Run Tests Before Committing Code Changes**

- **MANDATORY**: When modifying source code (`.php` files), run tests BEFORE committing:
  ```bash
  ./scripts/run.sh tests
  ```
- **MANDATORY**: When modifying error messages, verify affected tests expect the new message
- Tests catch issues that code review might miss (changed behavior, broken assertions, etc.)
- Pushing failing code wastes reviewer time and blocks CI

**Other Requirements:**
- Never commit code without running **tests** and **linting**
- Never remove or weaken tests to make them pass
- Always review Claude's suggestions before execution
- Always prefix commits with the issue ID (CIVIMM-###)
- Claude must never push commits automatically without human review
- Never commit `civicrm.settings.php` or any file containing credentials
- Never modify auto-generated files (`paymentprocessingcore.civix.php`, DAO classes) manually
- If unsure, stop and consult a senior developer

**Sensitive Files (Never Commit):**
- `civicrm.settings.php` (contains credentials)
- `.env` files
- Any files with credentials or secrets

**Auto-Generated Files (Do Not Edit Manually):**
- `paymentprocessingcore.civix.php` (regenerate with `civix`)
- `CRM/PaymentProcessingCore/DAO/*.php` (regenerate from XML schemas)
- Files in `xml/schema/CRM/PaymentProcessingCore/*.entityType.php` (auto-generated)

---

## 13. Deployment & Release Process

**Pre-Deployment Checklist:**
- ✅ All tests pass (unit-test.yml)
- ✅ Linting passes (linters.yml)
- ✅ PHPStan passes (phpstan.yml)
- ✅ Code reviewed and PR approved
- ✅ Version bumped in `info.xml` if needed
- ✅ CHANGELOG updated (if applicable)

**Release Process:**
1. Merge PR to target branch (e.g., `master`)
2. GitHub Actions automatically creates `{branch}-test` branch with vendor dependencies
3. For production releases, use the built release from GitHub releases page
4. Production deployments MUST use built releases (includes `vendor/` directory)

**Important Notes:**
- Repository does NOT include `vendor/` directory in source code
- Test branches include dependencies for testing purposes

---

## 14. Pre-Merge Validation Checklist

| Check | Requirement |
|--------|-------------|
| ✅ Tests pass | PHPUnit tests all green in CI |
| ✅ Linting passes | PHPCS reports no violations |
| ✅ PHPStan passes | Level 9 static analysis clean on changed files |
| ✅ Commit prefix | Uses CIVIMM-### format |
| ✅ PR Template used | `.github/PULL_REQUEST_TEMPLATE.md` completed |
| ✅ No sensitive data | No credentials in code |
| ✅ Code reviewed | At least one approval from team member |

---

## 15. CiviCRM Extension Specifics

**Extension Structure:**
- `info.xml`: Extension metadata, dependencies, version
- `paymentprocessingcore.php`: Hook implementations entry point
- `paymentprocessingcore.civix.php`: Auto-generated CiviX boilerplate (DO NOT EDIT)
- `xml/schema/`: Entity schema definitions
- `sql/`: Database schema and upgrade scripts
- `composer.json`: PHP dependencies

**CiviCRM Commands:**
```bash
# Enable extension
cv en paymentprocessingcore

# Disable extension
cv dis paymentprocessingcore

# Uninstall extension
cv ext:uninstall paymentprocessingcore

# Upgrade extension
cv api Extension.upgrade

# Clear cache
cv flush
```

**Database Schema Changes:**
When modifying entities in `xml/schema/`, regenerate DAO files:

```bash
# Using the Docker test environment (RECOMMENDED - Claude Code can run this)
./scripts/run.sh setup    # One-time setup
./scripts/run.sh civix    # Regenerate DAO files

# OR if working in a full CiviCRM dev environment
cd /path/to/civicrm/sites/default
civix generate:entity-boilerplate -x /path/to/extension
```

**Important Notes for Claude Code:**
- ✅ **Can run civix via Docker test environment** - use `./scripts/run.sh civix`
- ⚠️ Requires Docker to be running and test environment to be set up
- ✅ DAO files are **automatically regenerated** during extension installation/upgrade
- 📝 Always regenerate DAO files after modifying XML schemas
- 🔧 Test environment replicates exact CI setup (MariaDB, full CiviCRM)

---

By following this file, **Claude Code** can act as a reliable assistant within our workflow — improving speed, not replacing review or standards.

**Happy coding with Claude Code 🚀**
