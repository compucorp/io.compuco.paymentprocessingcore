# Payment Processing Core

![Build Status](https://github.com/compucorp/io.compuco.paymentprocessingcore/workflows/Tests/badge.svg)
![License](https://img.shields.io/badge/license-AGPL--3.0-blue.svg)

Generic payment processing infrastructure for CiviCRM - provides foundational entities for payment attempt tracking and webhook event management across multiple payment processors.

## Overview

Payment Processing Core is a foundational CiviCRM extension that provides reusable database entities and patterns for payment processor extensions.

**What it provides:**

- Payment attempt tracking entity
- Webhook event logging entity
- Multi-processor support
- API4 integration

## Requirements

- **PHP:** 8.1+ (7.4+ minimum)
- **CiviCRM:** 6.4.1+
- **Database:** MySQL 5.7+ / MariaDB 10.3+

## Installation

### Via Command Line

```bash
# Download and enable
cv ext:download io.compuco.paymentprocessingcore
cv ext:enable paymentprocessingcore
```

### For Developers

```bash
# Clone repository
git clone https://github.com/compucorp/io.compuco.paymentprocessingcore.git
cd io.compuco.paymentprocessingcore

# Install dependencies
composer install

# Enable extension
cv en paymentprocessingcore
```

## Usage

This extension provides infrastructure for payment processor extensions. See the [developer documentation](CLAUDE.md) for detailed usage information.

### For Payment Processor Developers

The extension provides three main entities via API4:

- `PaymentAttempt` - Track payment sessions and attempts
- `PaymentWebhook` - Log and deduplicate webhook events
- `PaymentProcessorCustomer` - Store customer IDs across processors

#### Example: Payment Attempts

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

#### Example: Contribution Completion Service

```php
// Get service from container
$service = \Civi::service('paymentprocessingcore.contribution_completion');

// Complete a pending contribution
try {
  $result = $service->complete(
    $contributionId,      // CiviCRM contribution ID
    $transactionId,       // Payment processor transaction ID (e.g., ch_123)
    $feeAmount,          // Optional: Fee amount (e.g., 2.50)
    $sendReceipt         // Optional: TRUE/FALSE/NULL (NULL = auto-detect from contribution page)
  );

  if ($result['success']) {
    // Contribution completed successfully
  }
}
catch (\Civi\Paymentprocessingcore\Exception\ContributionCompletionException $e) {
  // Handle error
  $context = $e->getContext();
}
```

**Features:**
- ✅ Idempotent (safe to call multiple times)
- ✅ Automatically handles accounting entries via `Contribution.completetransaction` API
- ✅ Auto-detects receipt settings from contribution page
- ✅ Records payment processor fees
- ✅ Detailed error messages with context

#### Example: Customer ID Management

```php
// Get service from container
$customerService = \Civi::service('paymentprocessingcore.payment_processor_customer');

// Get or create customer ID
try {
  $customerId = $customerService->getOrCreateCustomerId(
    $contactId,
    $paymentProcessorId,
    function() use ($stripeClient, $email, $name) {
      // This callback only runs if customer doesn't exist
      $customer = $stripeClient->customers->create([
        'email' => $email,
        'name' => $name,
      ]);
      return $customer->id;
    }
  );

  // Use $customerId in payment flow
}
catch (\Civi\Paymentprocessingcore\Exception\PaymentProcessorCustomerException $e) {
  // Handle error
  $context = $e->getContext();
}
```

**Features:**
- ✅ Prevents duplicate customers across payment processors
- ✅ Reuses existing customers (reduces API calls)
- ✅ Works with Stripe, GoCardless, ITAS, Deluxe, etc.
- ✅ Simple callback pattern for customer creation

#### Example: Webhook Processing System

The extension provides a queue-based webhook processing system that automatically handles events from all registered payment processors.

**How It Works:**

1. Payment processor receives webhook from external service (Stripe, GoCardless, etc.)
2. Processor extension verifies signature and saves event to `civicrm_payment_webhook`
3. Event is added to processor-specific queue
4. Scheduled job processes queued events with retry logic
5. Handlers execute processor-specific business logic

**For Payment Processor Developers:**

```php
// In your processor extension's webhook endpoint (CRM_YourProcessor_Page_Webhook):
class CRM_YourProcessor_Page_Webhook extends CRM_Core_Page {
  public function run(): void {
    $payload = file_get_contents('php://input');
    $signature = $_SERVER['HTTP_YOUR_PROCESSOR_SIGNATURE'] ?? '';

    // Get webhook receiver service
    $receiver = \Civi::service('yourprocessor.webhook_receiver');
    $receiver->handleRequest($payload, $signature);

    CRM_Utils_System::civiExit();
  }
}

// In your webhook receiver service:
class YourProcessorWebhookReceiverService {
  private WebhookQueueService $queueService;

  public function handleRequest(string $payload, string $signature): void {
    // 1. Verify signature (processor-specific)
    $event = $this->verifyAndParseEvent($payload, $signature);

    // 2. Save to generic webhook table
    $webhookId = $this->saveWebhookEvent($event);

    // 3. Queue for processing
    $this->queueService->addTask(
      'yourprocessor',  // processor type
      $webhookId,
      ['event_data' => $event->toArray()]
    );
  }
}

// Implement handlers for specific event types:
// Option 1 (Preferred): Implement the interface directly
class PaymentSuccessHandler implements WebhookHandlerInterface {
  public function handle(int $webhookId, array $params): string {
    $eventData = $params['event_data'];

    // Your business logic here
    // Use ContributionCompletionService to complete contributions

    return 'applied'; // or 'noop', 'ignored_out_of_order'
  }
}

// Option 2 (Fallback): Duck typing - if autoload issues occur
// The registry will wrap this in an Adapter automatically
class PaymentSuccessHandler {
  public function handle(int $webhookId, array $params): string {
    // Same signature as interface - registry validates at runtime
    return 'applied';
  }
}

// Register handlers in ServiceContainer.php:
private function registerWebhookHandlers(): void {
  if ($this->container->hasDefinition('paymentprocessingcore.webhook_handler_registry')) {
    $registry = $this->container->getDefinition('paymentprocessingcore.webhook_handler_registry');

    $registry->addMethodCall('registerHandler', [
      'yourprocessor',           // processor type
      'payment.succeeded',       // event type
      'yourprocessor.handler.payment_success',  // handler service ID
    ]);
  }
}
```

**Scheduled Job:**

The extension automatically creates a scheduled job "Process Payment Webhooks" that runs continuously. It automatically discovers and processes webhooks from all registered payment processors.

**Features:**
- ✅ Automatic retry with exponential backoff (5min, 15min, 45min)
- ✅ Stuck webhook recovery (resets webhooks stuck in "processing" > 30 minutes)
- ✅ Batch processing to prevent job timeouts (250 events per processor per run)
- ✅ Idempotent processing (safe to call multiple times)
- ✅ Multi-processor support (Stripe, GoCardless, Deluxe auto-discovered)

### For CiviCRM Administrators

This extension provides infrastructure used by payment processors. After installation:

1. Install payment processor extensions (Stripe, GoCardless, etc.)
2. Configure payment processors as normal
3. PaymentProcessingCore works automatically in the background

No additional configuration is required.

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

# Static analysis
./scripts/run.sh phpstan-changed
```

### Contributing

See [CLAUDE.md](CLAUDE.md) for detailed development guidelines.

## Support

- **Issues:** [GitHub Issues](https://github.com/compucorp/io.compuco.paymentprocessingcore/issues)
- **Email:** hello@compuco.io
- **Documentation:** [Developer Guide](CLAUDE.md)

## License

This extension is licensed under [AGPL-3.0](LICENSE.txt).

## Credits

Developed and maintained by [Compuco](https://compuco.io).
