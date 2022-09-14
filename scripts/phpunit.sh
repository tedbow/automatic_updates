#!/usr/bin/env bash

# NAME
#     phpunit.sh - Runs PHPUnit tests.
#
# SYNOPSIS
#     bash phpunit.sh
#
# DESCRIPTION
#     Run all Automatic Updates PHPUnit tests.
#
#     It is assumed that this module is inside a Drupal core installation, in
#     modules or modules/contrib. See setup_local_dev.sh.

cd "$(dirname "$0")" || exit 0;

# Find PHPUnit in Drupal core. Check up to three directories up.
DIR=$(pwd)
for i in {0..3}; do
  DIR=$(dirname "$DIR")
  PHPUNIT_BIN="$DIR/vendor/bin/phpunit"
  PHPUNIT_CONFIG="$DIR/core/phpunit.xml"
  if test -f "$PHPUNIT_BIN"; then
    break
  fi
done

# Exit if PHPUnit can't be found.
if test ! -f "$PHPUNIT_BIN"; then
  echo "Could not find PHPUnit. Are you inside a Drupal site's 'modules' directory?"
  exit 1
fi

# Exit if PHPUnit can't be found.
if test ! -f "$PHPUNIT_CONFIG"; then
  echo "Could not find PHPUnit configuration. See setup_local_dev.sh."
  exit 1
fi

# Run PHPUnit on the module directory.
php "$PHPUNIT_BIN" \
  -c "$PHPUNIT_CONFIG" \
  "$(cd .. && pwd)"
