#!/bin/bash

# Colors for output
BLUE='\033[0;34m'
GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Get the action (check or generate-baseline)
ACTION="${1:-check}"

case "$ACTION" in
  check)
    echo -e "${BLUE}🔍 Running PHPStan analysis...${NC}"
    docker compose -f docker-compose.phpstan.yml run --rm phpstan
    EXIT_CODE=$?

    if [ $EXIT_CODE -eq 0 ]; then
      echo -e "${GREEN}✅ PHPStan analysis complete - no errors found${NC}"
    else
      echo -e "${RED}❌ PHPStan found errors${NC}"
    fi

    # Clean up
    docker compose -f docker-compose.phpstan.yml down 2>/dev/null
    exit $EXIT_CODE
    ;;

  generate-baseline)
    echo -e "${BLUE}📝 Generating PHPStan baseline...${NC}"
    docker compose -f docker-compose.phpstan.yml run --rm phpstan analyse -c phpstan.neon --generate-baseline=phpstan-baseline.neon --memory-limit=1G
    EXIT_CODE=$?

    if [ $EXIT_CODE -eq 0 ]; then
      echo -e "${GREEN}✅ Baseline generated successfully${NC}"
      echo -e "${BLUE}ℹ️  Baseline saved to phpstan-baseline.neon${NC}"
    else
      echo -e "${RED}❌ Failed to generate baseline${NC}"
    fi

    # Clean up
    docker compose -f docker-compose.phpstan.yml down 2>/dev/null
    exit $EXIT_CODE
    ;;

  *)
    echo "Usage: $0 {check|generate-baseline}"
    echo ""
    echo "Commands:"
    echo "  check              Run PHPStan analysis"
    echo "  generate-baseline  Generate baseline file for existing errors"
    exit 1
    ;;
esac