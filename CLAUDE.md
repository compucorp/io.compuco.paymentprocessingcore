# Claude Code Development Guide

This file defines how **Claude Code (Anthropic)** should assist with this project.

> **For project overview, installation, and usage examples, see [README.md](README.md).**

Claude Code can edit files, plan changes, and run commands — but must follow all development standards described here.

---

## 1. Quick Reference

| Resource | Location |
|----------|----------|
| Project Overview | [README.md](README.md) |
| Usage Examples | [README.md](README.md#usage) |
| PR Template | `.github/PULL_REQUEST_TEMPLATE.md` |
| Linting Rules | `phpcs-ruleset.xml` |
| PHPStan Config | `phpstan.neon` |

---

## 2. Development Environment

### Running Tests, Linter, PHPStan

```bash
# Setup Docker environment (one-time)
./scripts/run.sh setup

# Run all tests
./scripts/run.sh tests

# Run linter on changed files
./scripts/lint.sh check

# Run PHPStan on changed files
./scripts/run.sh phpstan-changed

# Regenerate DAO files after schema changes
./scripts/run.sh civix
```

### CiviCRM Core Reference (Optional)

For debugging CiviCRM API issues:

```bash
git clone https://github.com/compucorp/civicrm-core.git
cd civicrm-core && git checkout 6.4.1-patches
```

---

## 3. Pull Request Guidelines

### PR Title Format
```
CIVIMM-###: Short description
```

### Required Sections (from template)
- **Overview**: Non-technical description
- **Before**: Current state
- **After**: What changed
- **Technical Details**: Code snippets, patterns used
- **Core overrides**: If any CiviCRM core files are patched

### Handling Review Feedback

**NEVER blindly implement feedback.** Always:

1. Analyze if the suggestion makes technical sense
2. Consider implications (database constraints, type safety, performance)
3. Ask clarifying questions if unsure
4. Explain your reasoning for accepting or rejecting
5. Get user approval before committing changes

---

## 4. Commit Message Convention

```
CIVIMM-###: Short imperative description
```

**Rules:**
- Keep under 72 characters
- Use present tense ("Add", "Fix", "Refactor")
- **NO AI attribution** (no "Co-Authored-By: Claude")

**Examples:**
```
CIVIMM-456: Add PaymentAttempt entity with multi-processor support
CIVIMM-789: Fix webhook deduplication race condition
```

---

## 5. Code Quality Standards

### Unit Testing
- **Mandatory** for all new features and bug fixes
- Extend `BaseHeadlessTest` for test classes
- Use fabricators in `tests/phpunit/Fabricator/`
- Never modify tests just to make them pass

### Linting (PHPCS)
- Follow CiviCRM Drupal coding standards
- All files must end with a newline
- Run `./scripts/lint.sh fix` to auto-fix issues

### Static Analysis (PHPStan Level 9)
- Strictest PHP type checking
- Never regenerate baseline to "fix" errors - fix the code
- Use `@phpstan-param` for generic types that linter doesn't support

### Linter + PHPStan Compatibility Pattern
```php
/**
 * @param array $params           // Linter sees this
 * @phpstan-param array<string, mixed> $params  // PHPStan sees this
 */
```

---

## 6. CI Requirements

All code must pass before merging:

| Check | Command | CI Workflow |
|-------|---------|-------------|
| Tests | `./scripts/run.sh tests` | `unit-test.yml` |
| Linting | `./scripts/lint.sh check` | `linters.yml` |
| PHPStan | `./scripts/run.sh phpstan-changed` | `phpstan.yml` |

---

## 7. Architecture Overview

### Namespaces

| Namespace | Directory | Purpose |
|-----------|-----------|---------|
| `CRM_*` | `CRM/` | Traditional CiviCRM (DAO, BAO) |
| `Civi\Paymentprocessingcore\*` | `Civi/` | Modern services |

### Key Services

| Service | Purpose |
|---------|---------|
| `ContributionCompletionService` | Complete pending contributions |
| `ContributionFailureService` | Handle failed contributions |
| `WebhookQueueService` | Queue webhook events |
| `WebhookHandlerRegistry` | Map events to handlers |
| `PaymentProcessorCustomerService` | Manage customer IDs |

### Webhook Handler Pattern

Handlers can implement `WebhookHandlerInterface` directly (preferred) or use duck typing (fallback). The registry uses the Adapter pattern for duck-typed handlers.

```php
// Option 1: Implement interface (preferred)
class MyHandler implements WebhookHandlerInterface {
  public function handle(int $webhookId, array $params): string {
    return 'applied';
  }
}

// Option 2: Duck typing (fallback - wrapped in Adapter)
class MyHandler {
  public function handle(int $webhookId, array $params): string {
    return 'applied';
  }
}
```

---

## 8. Workflow with Claude Code

### Recommended Flow
1. **Explain** – Describe the issue
2. **Plan** – Use Plan Mode for complex tasks
3. **Review** – Verify plan before implementation
4. **Implement** – Apply changes
5. **Verify** – Run tests and linting

### Request Confirmation Before
- Deleting or overwriting files
- Database migrations
- Modifying auto-generated files
- Changes to `xml/schema/` files

---

## 9. Safety Rules

### CRITICAL: Run Tests Before Committing
```bash
./scripts/run.sh tests
```

### Never Commit
- `civicrm.settings.php` (credentials)
- `.env` files
- Any secrets or API keys

### Never Edit Manually
- `paymentprocessingcore.civix.php`
- `CRM/PaymentProcessingCore/DAO/*.php`
- Files in `xml/schema/*.entityType.php`

### Other Rules
- Never push without running tests and linting
- Never remove tests to make them pass
- Always prefix commits with issue ID
- Never push automatically without human review

---

## 10. Pre-Merge Checklist

| Check | Requirement |
|-------|-------------|
| ✅ Tests pass | PHPUnit all green |
| ✅ Linting passes | PHPCS no violations |
| ✅ PHPStan passes | Level 9 clean |
| ✅ Commit prefix | `CIVIMM-###` format |
| ✅ PR template used | All sections filled |
| ✅ No sensitive data | No credentials |
| ✅ Code reviewed | At least one approval |

---

## 11. Common Commands

```bash
# Git
git status && git diff

# Testing
./scripts/run.sh setup    # One-time Docker setup
./scripts/run.sh tests    # Run all tests
./scripts/run.sh test path/to/Test.php  # Run specific test

# Code Quality
./scripts/lint.sh check   # Check linting
./scripts/lint.sh fix     # Auto-fix linting
./scripts/run.sh phpstan-changed  # PHPStan on changed files

# CiviCRM
./scripts/run.sh civix    # Regenerate DAO files
./scripts/run.sh cv api Contact.get  # Run cv commands
./scripts/run.sh shell    # Shell into container
```

---

## 12. Example Prompts

| Task | Prompt |
|------|--------|
| Generate tests | "Create PHPUnit tests for `PaymentAttemptService::createAttempt()` covering success and error cases." |
| Summarize PR | "Summarize commits into PR description using template for CIVIMM-123." |
| Fix linting | "Fix PHPCS violations in `ContributionCompletionService.php`." |
| Add service | "Create `RefundService` following existing service patterns." |
