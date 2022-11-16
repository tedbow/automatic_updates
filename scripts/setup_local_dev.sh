#!/usr/bin/env bash

# NAME
#     setup_local_dev.sh - Set up a local development environment.
#
# SYNOPSIS
#     bash scripts/setup_local_dev.sh
#
# DESCRIPTION
#     Set up a local development environment for contributing to the Automatic
#     Updates Drupal module, including cloning Drupal core and physically
#     installing the module and its dependencies. It does NOT set up a web
#     server or install Drupal in a database.

# cSpell:disable

# GNU realpath can't be depended upon to always be available. Simulate it.
# https://stackoverflow.com/questions/3572030/bash-script-absolute-path-with-os-x
safe_realpath() {
  [[ $1 = /* ]] && echo "$1" || echo "$PWD/${1#./}"
}

# Customizations: set any of these environment variables in your shell (i.e.,
# your terminal session) to override their default values.
# @see https://www.serverlab.ca/tutorials/linux/administration-linux/how-to-set-environment-variables-in-linux/
# Variables beginning with an underscore (_) cannot be overridden.
DRUPAL_CORE_BRANCH=${DRUPAL_CORE_BRANCH:="10.0.x"}
DRUPAL_CORE_SHALLOW_CLONE=${DRUPAL_CORE_SHALLOW_CLONE:="TRUE"}
AUTOMATIC_UPDATES_BRANCH=${AUTOMATIC_UPDATES_BRANCH:="8.x-2.x"}
SITE_DIRECTORY=${SITE_DIRECTORY:="auto_updates_dev"}
_SITE_DIRECTORY_REALPATH=$(safe_realpath "$SITE_DIRECTORY")
_SITE_DIRECTORY_BASENAME=$(basename "$_SITE_DIRECTORY_REALPATH")
SITE_HOST=${SITE_HOST:="$_SITE_DIRECTORY_BASENAME.test"}

MODULE_CLONE_DIRECTORY="modules/contrib/automatic_updates"

# Handle command-line options. (Usable when run from a local clone.)
while getopts ":fn" option; do
   case $option in
      f) # Force: delete existing site directory, if present.
         FORCE="TRUE"
         ;;
      n) # No interaction: skip prompt and continue automatically.
         NO_INTERACTION="TRUE"
         ;;
   esac
done

# Prevent the user from losing work in case the site directory already exists.
if test -e "$SITE_DIRECTORY"; then
  if test ! "$FORCE"; then
    # Exit with a warning.
    cat << DANGER

$(printf "\e[1;41m DANGER! \e[0m \e[33m")"$_SITE_DIRECTORY_REALPATH" already exists.
$(printf "\e[1;41m         \e[0m")
$(printf "\e[1;41m         \e[0m") If you destroy it, any changes to the Automatic Updates module inside it will be lost forever.
$(printf "\e[1;41m         \e[0m") Consider moving the directory to another location as a backup instead.
$(printf "\e[1;41m         \e[0m")
$(printf "\e[1;41m         \e[0m") Otherwise, if you know what you're doing and still want to continue, make sure any changes you want to
$(printf "\e[1;41m         \e[0m") keep have been committed and pushed to an appropriate remote. Then delete the directory and try again:
$(printf "\e[1;41m         \e[0m")
$(printf "\e[1;41m         \e[0m") rm -rf "$_SITE_DIRECTORY_REALPATH"

DANGER
    exit 1
  else
    # Automatically delete the existing site directory.
    chmod -R u+w "$_SITE_DIRECTORY_REALPATH"
    rm -rf "$_SITE_DIRECTORY_REALPATH"
  fi
fi

# Prompt for confirmation.
if test ! "$NO_INTERACTION"; then
  cat << WARNING
You are about to create an Automatic Updates development environment at "$SITE_DIRECTORY". This will download
as much as 100 MB of data and may take several minutes to complete, depending on your Internet connection.

WARNING
  read -p "Do you want to continue? [yN] " -n 1 -r
  echo
  if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    # Exit from a function or non-interactive shell but not an interactive one.
    [[ "$0" = "$BASH_SOURCE" ]] && exit 1 || return 1
  fi
  echo
fi

# Clone Drupal core.
if [[ "$DRUPAL_CORE_SHALLOW_CLONE" == "TRUE" ]]; then
  DRUPAL_CORE_CLONE_DEPTH="--depth 1"
fi
git clone \
  https://git.drupalcode.org/project/drupal.git \
  --branch "$DRUPAL_CORE_BRANCH" \
  $DRUPAL_CORE_CLONE_DEPTH \
  "$SITE_DIRECTORY"

cd "$SITE_DIRECTORY" || exit 1

# Prevent site config and external dependencies from getting committed to the
# Drupal core clone--which would never be desirable, even for Core contribution.
echo "
# PhpStorm config
.idea
# Custom and contributed Drupal extensions
libraries
modules
profiles
themes
# Drupal site configuration
default
sites
# Composer libraries
vendor
" | tee -a .git/info/exclude

# Create default site settings.
cp "$_SITE_DIRECTORY_REALPATH/sites/default/default.settings.php" \
   "$_SITE_DIRECTORY_REALPATH/sites/default/settings.php"

# Set trusted_host_patterns configuration.
TRUSTED_HOST_PATTERN="${SITE_HOST//\./\\.}"
  echo "
\$settings['trusted_host_patterns'] = [
  '^$TRUSTED_HOST_PATTERN\$',
];" \
  | tee -a sites/default/settings.php

# Set path to Composer configuration, if possible.
COMPOSER_PATH=$(which composer);
if test ! -z "$COMPOSER_PATH"; then
  echo "
\$config['package_manager.settings']['executables']['composer'] = '$COMPOSER_PATH';" \
  | tee -a sites/default/settings.php
fi

# Enable verbose error display in the browser.
echo "
\$config['system.logging']['error_level'] = 'verbose';" \
  | tee -a sites/default/settings.php

# Clone the Automatic Updates repo into place. (It will still be
# `composer require`d below to bring in its dependencies.)
git clone \
  --branch "$AUTOMATIC_UPDATES_BRANCH" -- \
  https://git.drupalcode.org/project/automatic_updates.git \
  "$MODULE_CLONE_DIRECTORY"

# Tell Composer to look for the package in the local clone. This is done rather
# than MERELY cloning the module so that the composer.json of the code under
# development is actually exercised and dependencies don't have to be added
# manually.
composer config \
  repositories.automatic_updates \
  path \
  "$MODULE_CLONE_DIRECTORY"

# Prevent Composer from symlinking path repositories.
export COMPOSER_MIRROR_PATH_REPOS=1

# Remove the Composer platform PHP requirement, but only on Drupal 9.
CORE_BRANCH=$(git rev-parse --abbrev-ref HEAD)
if [[ "$CORE_BRANCH" =~ 9.* ]]; then
  composer config --unset platform.php
fi

# Prevent Composer from installing symlinks from common packages known to
# contain them.
# @see https://www.drupal.org/docs/develop/using-composer/using-drupals-vendor-hardening-composer-plugin
composer config --json extra.drupal-core-vendor-hardening.drush/drush '["docs"]'
composer config --json extra.drupal-core-vendor-hardening.grasmash/yaml-expander '["scenarios"]'

# Require the module using the checked out dev branch.
composer require \
  --no-ansi \
  drupal/automatic_updates:dev-"$AUTOMATIC_UPDATES_BRANCH"

# `composer install` only installs root package development dependencies. So add
# automatic_updates' development dependencies to the root.
# @see https://getcomposer.org/doc/04-schema.md#require-dev
composer require --dev colinodell/psr-testlogger:^1

# Revert needless changes to Core Composer metapackages.
git checkout -- "$_SITE_DIRECTORY_REALPATH/composer/Metapackage"

# Configure PHPUnit.
PHPUNIT_XML=$(sed 's/env name="SIMPLETEST_BASE_URL" value=""/env name="SIMPLETEST_BASE_URL" value="http:\/\/'$SITE_HOST'"/' "$_SITE_DIRECTORY_REALPATH/core/phpunit.xml.dist")
PHPUNIT_XML=$(sed 's/env name="SIMPLETEST_DB" value=""/env name="SIMPLETEST_DB" value="sqlite:\/\/sites\/default\/files\/db.sqlite"/' <<< "$PHPUNIT_XML")
echo "$PHPUNIT_XML" > "$_SITE_DIRECTORY_REALPATH/core/phpunit.xml"

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
$(printf "\e[0m")
--------------------------------------------------------------------------------
$(printf "\e[1;32m")
   You're ready to start developing:
$(printf "\e[0m")
   - Point your web server at the configured site directory below. (No
     instructions are provided for this step yet.)

         Web root: $_SITE_DIRECTORY_REALPATH
         Site URL: http://$SITE_HOST

   - Make and commit code changes to the module repository below. Changes made
     anywhere else will not be captured.

         Module repo: $_SITE_DIRECTORY_REALPATH/$MODULE_CLONE_DIRECTORY

  For information on creating issue forks and merge requests see
  https://www.drupal.org/docs/develop/git/using-git-to-contribute-to-drupal/creating-issue-forks-and-merge-requests

$(printf "\e[1;34m")================================================================================

DONE
