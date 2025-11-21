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

The extension provides two main entities via API4:

- `PaymentAttempt` - Track payment sessions and attempts
- `PaymentWebhook` - Log and deduplicate webhook events

Example usage:

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
