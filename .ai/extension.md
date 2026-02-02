<!-- .ai/extension.md v1.5 | Last updated: 2026-02-02 -->

# CiviCRM Extension Development

This file covers extension-specific structure, schema management, and testing patterns.

> **CiviCRM core patterns:** See [civicrm.md](civicrm.md) for API4, hooks, services, and PHPStan patterns.

---

## 1. Extension Structure

```
io.compuco.paymentprocessingcore/
  info.xml              # Extension metadata, dependencies, version
  paymentprocessingcore.php  # Hook implementations entry point
  paymentprocessingcore.civix.php  # Auto-generated CiviX boilerplate (DO NOT EDIT)
  CRM/                  # Traditional CiviCRM namespace (CRM_*)
    Paymentprocessingcore/
      DAO/              # Database Access Objects (auto-generated from XML)
      BAO/              # Business Access Objects (business logic)
      Page/             # UI pages
      Form/             # Form handlers
      Hook/             # Hook implementations
      Helper/           # Constants and helper classes
  Civi/                 # Modern namespace (Civi\Paymentprocessingcore\*)
    Paymentprocessingcore/
      Service/          # Business logic services
      DTO/              # Data Transfer Objects (typed API4 boundary)
      Utils/            # Utility classes
      Helper/           # Traits for shared functionality
      Hook/             # Modern hook implementations
      Factory/          # Factory classes
      Exception/        # Custom exception classes
  xml/schema/           # Entity schema definitions
  xml/Menu/             # Menu definitions
  sql/                  # Database schema and upgrade scripts
  templates/            # Smarty templates for UI
  js/                   # JavaScript files
  css/                  # Stylesheets
  tests/phpunit/        # PHPUnit tests (mirrors source structure)
  stubs/                # PHPStan stub files for dynamic classes
  scripts/              # Dev scripts (run.sh, lint.sh)
  phpstan.neon          # PHPStan configuration
  phpcs-ruleset.xml     # PHPCS linting rules
```

---

## 2. Key Services

| Service | Purpose |
|---------|---------|
| `ContributionCompletionService` | Complete pending contributions |
| `ContributionFailureService` | Handle failed contributions |
| `InstalmentGenerationService` | Generate instalment contributions for recurring |
| `WebhookQueueService` | Queue webhook events |
| `WebhookHandlerRegistry` | Map events to handlers |
| `PaymentProcessorCustomerService` | Manage customer IDs |

---

## 3. Webhook Handler Pattern

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

## 4. DTO Pattern

Use Data Transfer Objects at the boundary between CiviCRM's untyped Api4 arrays and typed service code:

```php
// Private constructor with static factory
$dto = RecurringContributionDTO::fromApiResult($apiRecord);

// Type-safe access via getters
$dto->getId();
$dto->getAmount();
$dto->getCampaignId(); // nullable
```

---

## 5. Database Schema Changes

When modifying entities in `xml/schema/`:

```bash
# Regenerate DAO files using Docker test environment
./scripts/run.sh setup    # One-time setup
./scripts/run.sh civix    # Regenerate DAO files
```

**Notes:**
- DAO files are auto-regenerated during extension installation/upgrade
- Always regenerate DAO files after modifying XML schemas
- Never edit DAO files manually

---

## 6. Testing Patterns

- Extend `BaseHeadlessTest` for all test classes
- Use fabricators in `tests/phpunit/Fabricator/` to create test data
- Mock external API calls where applicable
- Test positive, negative, and edge cases
- Store tests mirroring source structure

```php
class MyServiceTest extends BaseHeadlessTest {
  public function testSuccessCase(): void {
    // Arrange
    $fabricator = new MyEntityFabricator();
    $entity = $fabricator->fabricate();

    // Act
    $result = $this->service->process($entity['id']);

    // Assert
    $this->assertNotNull($result);
    $this->assertEquals('expected', $result['status']);
  }
}
```
