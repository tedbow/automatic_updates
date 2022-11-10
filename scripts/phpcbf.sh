#!/usr/bin/env bash

# NAME
#     phpcbf.sh - Automatically fixes standards violations where possible.
#
# SYNOPSIS
#     bash phpcbf.sh
#
# DESCRIPTION
#     Fix code compliance with Drupal core coding standards using PHP Code
#     Beautifier and Fixer (PHPCBF).
#
#     It is assumed that this module is inside a Drupal core installation, in
#     modules or modules/contrib. See setup_local_dev.sh.

# cSpell:disable

cd "$(dirname "$0")" || exit 0;

# Find PHPCBF in Drupal core. Check up to three directories up.
DIR=$(pwd)
for i in {0..3}; do
  DIR=$(dirname "$DIR")
  PHPCBF_BIN="$DIR/vendor/bin/phpcbf"
  PHPCS_CONFIG="$DIR/core/phpcs.xml.dist"
  if test -f "$PHPCBF_BIN"; then
    break
  fi
done

# Exit if PHPCBF can't be found.
if test ! -f "$PHPCBF_BIN"; then
  echo "Could not find PHPCBF. Are you inside a Drupal site's 'modules' directory?"
  exit 1
fi

# Run PHPCBF on the module directory.
php "$PHPCBF_BIN" \
  --colors \
  --standard="$PHPCS_CONFIG" \
  "$(cd .. && pwd)"
