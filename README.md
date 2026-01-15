# Payment Processing Core

![Build Status](https://github.com/compucorp/io.compuco.paymentprocessingcore/workflows/Tests/badge.svg)
![License](https://img.shields.io/badge/license-AGPL--3.0-blue.svg)

Generic payment processing infrastructure for CiviCRM - provides foundational services for payment attempt tracking and webhook event management across multiple payment processors.

## Overview

Payment Processing Core centralizes payment processing logic that was previously duplicated across payment processor extensions (Stripe, GoCardless, Deluxe, etc.).

**What it provides:**
- Payment attempt tracking entity
- Webhook event logging and deduplication
- Contribution completion/failure/cancellation services
- Queue-based webhook processing with retry logic
- Multi-processor support with auto-discovery

## Requirements

- **PHP:** 8.1+ (7.4+ minimum)
- **CiviCRM:** 6.4.1+
- **Database:** MySQL 5.7+ / MariaDB 10.3+

## Installation

```bash
# Download and enable
cv ext:download io.compuco.paymentprocessingcore
cv ext:enable paymentprocessingcore
```

For development:
```bash
git clone https://github.com/compucorp/io.compuco.paymentprocessingcore.git
cd io.compuco.paymentprocessingcore
composer install
cv en paymentprocessingcore
```

## Usage

### Entities (API4)

The extension provides three entities:

- `PaymentAttempt` - Track payment sessions and attempts
- `PaymentWebhook` - Log and deduplicate webhook events
- `PaymentProcessorCustomer` - Store customer IDs across processors

```php
use Civi\Api4\PaymentAttempt;

// Create a payment attempt
$attempt = PaymentAttempt::create(FALSE)
  ->addValue('contribution_id', $contributionId)
  ->addValue('contact_id', $contactId)
  ->addValue('processor_type', 'stripe')
  ->addValue('status', 'pending')
  ->execute()
  ->first();
```

### ContributionCompletionService

Complete pending contributions with idempotency:

```php
$service = \Civi::service('paymentprocessingcore.contribution_completion');

$result = $service->complete(
  $contributionId,      // CiviCRM contribution ID
  $transactionId,       // Payment processor transaction ID
  $feeAmount,           // Optional: Fee amount
  $sendReceipt          // Optional: TRUE/FALSE/NULL (auto-detect)
);

if ($result['success']) {
  // Contribution completed
}
```

**Features:** Idempotent, handles accounting entries, auto-detects receipt settings, records fees.

### PaymentProcessorCustomerService

Manage customer IDs across processors:

```php
$customerService = \Civi::service('paymentprocessingcore.payment_processor_customer');

$customerId = $customerService->getOrCreateCustomerId(
  $contactId,
  $paymentProcessorId,
  function() use ($stripeClient, $email) {
    // Only runs if customer doesn't exist
    $customer = $stripeClient->customers->create(['email' => $email]);
    return $customer->id;
  }
);
```

**Features:** Prevents duplicates, reuses existing customers, works with any processor.

### Webhook Processing System

Queue-based webhook processing with automatic retry:

```
Webhook → Verify signature → Save to DB → Queue → Process → Complete/Retry
```

**For Payment Processor Developers:**

1. Create webhook endpoint:
```php
class CRM_YourProcessor_Page_Webhook extends CRM_Core_Page {
  public function run(): void {
    $payload = file_get_contents('php://input');
    $signature = $_SERVER['HTTP_YOUR_PROCESSOR_SIGNATURE'] ?? '';

    $receiver = \Civi::service('yourprocessor.webhook_receiver');
    $receiver->handleRequest($payload, $signature);

    CRM_Utils_System::civiExit();
  }
}
```

2. Implement webhook handlers:
```php
// Option 1 (Preferred): Implement interface
use Civi\Paymentprocessingcore\Webhook\WebhookHandlerInterface;

class PaymentSuccessHandler implements WebhookHandlerInterface {
  public function handle(int $webhookId, array $params): string {
    // Your business logic
    return 'applied'; // or 'noop', 'ignored_out_of_order'
  }
}

// Option 2 (Fallback): Duck typing - if autoload issues occur
// Registry wraps in Adapter automatically
class PaymentSuccessHandler {
  public function handle(int $webhookId, array $params): string {
    return 'applied';
  }
}
```

3. Register handlers in ServiceContainer:
```php
$registry = $this->container->getDefinition('paymentprocessingcore.webhook_handler_registry');
$registry->addMethodCall('registerHandler', [
  'yourprocessor',           // processor type
  'payment.succeeded',       // event type
  'yourprocessor.handler.payment_success',  // handler service ID
]);
```

**Features:**
- Automatic retry with exponential backoff (5min → 15min → 45min)
- Stuck webhook recovery (resets after 30 minutes)
- Batch processing (250 events per processor per run)
- Multi-processor auto-discovery

### For CiviCRM Administrators

This extension provides infrastructure for payment processors. After installation:

1. Install payment processor extensions (Stripe, GoCardless, etc.)
2. Configure payment processors as normal
3. PaymentProcessingCore works automatically in the background

No additional configuration required.

## Development

### Setup

```bash
# Setup Docker test environment
./scripts/run.sh setup

# Run tests
./scripts/run.sh tests

# Run linter
./scripts/lint.sh check

# Run static analysis
./scripts/run.sh phpstan-changed
```

### Testing

```bash
# Run all tests
./scripts/run.sh tests

# Run specific test
./scripts/run.sh test tests/phpunit/Civi/Api4/PaymentAttemptTest.php
```

### Code Quality

```bash
# Linting
./scripts/lint.sh check
./scripts/lint.sh fix

# Static analysis (PHPStan level 9)
./scripts/run.sh phpstan-changed
```

## Contributing

See [CLAUDE.md](CLAUDE.md) for development guidelines including:
- PR and commit conventions
- Code quality standards
- CI requirements
- Architecture overview

## Support

- **Issues:** [GitHub Issues](https://github.com/compucorp/io.compuco.paymentprocessingcore/issues)
- **Email:** hello@compuco.io

## License

[AGPL-3.0](LICENSE.txt)

## Credits

Developed and maintained by [Compuco](https://compuco.io).
