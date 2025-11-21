#!/bin/bash
set -e

# Load environment configuration for defaults
source /extension/scripts/env-config.sh

# Default values
CIVICRM_VERSION="$DEFAULT_CIVICRM_VERSION"
CMS_VERSION="$DEFAULT_CMS_VERSION"

# Parse command-line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --civi-version)
            CIVICRM_VERSION="$2"
            shift 2
            ;;
        --cms-version)
            CMS_VERSION="$2"
            shift 2
            ;;
        -h|--help)
            echo "Usage: $0 [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --civi-version VERSION    CiviCRM version (default: $DEFAULT_CIVICRM_VERSION)"
            echo "  --cms-version VERSION     CMS version (default: $DEFAULT_CMS_VERSION)"
            echo "  -h, --help                Show this help message"
            echo ""
            echo "Examples:"
            echo "  $0                                    # Use defaults (CiviCRM $DEFAULT_CIVICRM_VERSION)"
            echo "  $0 --civi-version 5.51.3              # Use CiviCRM 5.51.3 (legacy civix)"
            echo "  $0 --civi-version 5.75.0 --cms-version 7.94"
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            echo "Use --help for usage information"
            exit 1
            ;;
    esac
done

echo "🚀 Setting up CiviCRM environment (CiviCRM ${CIVICRM_VERSION}, Drupal ${CMS_VERSION})..."

echo "📦 Installing required PHP extensions..."
apt update && apt install -y php-bcmath

echo "⬇️ Downgrading Composer to 2.2.5..."
composer self-update 2.2.5

echo "🗄️ Configuring MySQL..."
echo "SET GLOBAL sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''));" | mysql -u root --password=root --host=mysql

echo "🔧 Configuring amp..."
amp config:set --mysql_dsn=mysql://root:root@mysql:3306

echo "🏗️ Building Drupal site with CiviCRM ${CIVICRM_VERSION}..."
civibuild create drupal-clean --civi-ver $CIVICRM_VERSION --cms-ver $CMS_VERSION --web-root $WEB_ROOT

echo "🔗 Creating symlink to extension directory..."
ln -sfn /extension $CIVICRM_EXTENSIONS_DIR/io.compuco.paymentprocessingcore
echo "📋 Extension linked at $CIVICRM_EXTENSIONS_DIR/io.compuco.paymentprocessingcore -> /extension"

echo "📦 Installing PaymentProcessingCore dependencies..."
cd $CIVICRM_EXTENSIONS_DIR/io.compuco.paymentprocessingcore
composer install --no-dev 2>/dev/null || echo "No composer dependencies (expected for core infrastructure)"

echo "✅ Enabling PaymentProcessingCore extension..."
cv en io.compuco.paymentprocessingcore

echo "🗄️ Creating test database..."
echo "CREATE DATABASE IF NOT EXISTS civicrm_test;" | mysql -u root --password=root --host=mysql

echo "✅ CiviCRM environment setup complete!"
echo ""
echo "Environment: CiviCRM ${CIVICRM_VERSION}, Drupal ${CMS_VERSION}"
echo "You can now run:"
echo "  - civix (from extension directory)"
echo "  - phpunit9 (to run tests)"
echo "  - cv cli (for CiviCRM commands)"
