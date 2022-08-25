#!/usr/bin/env bash

# NAME
#     phpcs.sh - Check code for standards compliance.
#
# SYNOPSIS
#     bash phpcs.sh
#
# DESCRIPTION
#     Check for compliance with Drupal core coding standards using
#     PHP_CodeSniffer (PHPCS).
#
#     It is assumed that this module is inside a Drupal core installation, in
#     modules or modules/contrib. See setup_local_dev.sh.

cd "$(dirname "$0")" || exit 0;

## Find PHPCS in Drupal core. Check up to three directories up.
DIR=$(pwd)
for i in {0..3}; do
  DIR=$(dirname "$DIR")
  PHPCS_BIN="$DIR/vendor/bin/phpcs"
  PHPCS_CONFIG="$DIR/core/phpcs.xml.dist"
  if test -f "$PHPCS_BIN"; then
    break
  fi
done

# Exit if PHPCS can't be found.
if test ! -f "$PHPCS_BIN"; then
  echo "Could not find PHPCS. Are you inside a Drupal site's 'modules' directory?"
  exit 1
fi

# Run PHPCS on the module directory.
php "$PHPCS_BIN" \
  --colors \
  --standard="$PHPCS_CONFIG" \
  "$(cd .. && pwd)"
