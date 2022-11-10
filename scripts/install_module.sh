#!/usr/bin/env bash

# NAME
#     install_module.sh - Re/install the module in a local dev environment.
#
# SYNOPSIS
#     bash scripts/install_module.sh
#
# DESCRIPTION
#     Install this module physically (i.e., code only, no database changes) into
#     an existing local development environment, e.g., one created with
#     scripts/setup_local_dev.sh. Only files committed to Git will be affected.
#     Excluded files such as settings.php will not be changed or deleted.

# cSpell:disable

cd "$(dirname "$0")/../" || exit;

MODULE_DIRECTORY=$(pwd)

# Find the site root directory. Check up to three directories above.
DIR=$(pwd)
for i in {0..3}; do
  DIR=$(dirname "$DIR")
  if test -f "$DIR/core/composer.json"; then
    SITE_DIRECTORY="$DIR"
    break
  fi
done

# Exit if the site can't be found.
if test -z "$SITE_DIRECTORY"; then
  echo "Could not find the local development environment. Are you inside a Drupal site's 'modules' directory?"
  exit 1
fi

cd "$SITE_DIRECTORY" || exit 1

# Eliminate any changes to the environment because they will need to be
# overwritten--especially Composer files.
git reset --hard

# Tell Composer to look for the package in the local clone. This is done rather
# than MERELY cloning the module so that the composer.json of the code under
# development is actually exercised and dependencies don't have to be added
# manually.
composer config \
  repositories.automatic_updates \
  path \
  "$MODULE_DIRECTORY"

# Prevent Composer from symlinking path repositories.
export COMPOSER_MIRROR_PATH_REPOS=1

# Update the Composer platform PHP emulation.
composer config platform.php 7.4.0

# Prevent Composer from installing symlinks from common packages known to
# contain them.
# @see https://www.drupal.org/docs/develop/using-composer/using-drupals-vendor-hardening-composer-plugin
composer config --json extra.drupal-core-vendor-hardening.drush/drush '["docs"]'
composer config --json extra.drupal-core-vendor-hardening.grasmash/yaml-expander '["scenarios"]'

# Require the module using the checked out dev branch.
composer require \
  --no-ansi \
  drupal/automatic_updates:*@dev

# Revert needless changes to Core Composer metapackages.
git checkout -- "$SITE_DIRECTORY/composer/Metapackage"

cat << DONE

$(printf "\e[1;34m")================================================================================
$(printf "\e[1;33m")
   oooooooooo.      .oooooo.    ooooo      ooo  oooooooooooo   .o.
   '888'   'Y8b    d8P'  'Y8b   '888b.     '8'  '888'     '8   888
    888      888  888      888   8 '88b.    8    888           888
    888      888  888      888   8   '88b.  8    888oooo8      Y8P
    888      888  888      888   8     '88b.8    888    '      '8'
    888     d88'  '88b    d88'   8       '888    888       o   .o.
   o888bood8P'     'Y8bood8P'   o8o        '8   o888ooooood8   Y8P

$(printf "\e[1;34m")================================================================================

DONE
