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

# Customizations: set any of these environment variables in your shell (i.e.,
# your terminal session) to override their default values.
# @see https://www.serverlab.ca/tutorials/linux/administration-linux/how-to-set-environment-variables-in-linux/
DRUPAL_CORE_BRANCH=${DRUPAL_CORE_BRANCH:="9.5.x"}
AUTOMATIC_UPDATES_BRANCH=${AUTOMATIC_UPDATES_BRANCH:="8.x-2.x"}
SITE_DIRECTORY=${SITE_DIRECTORY:="auto-updates-dev"}

# GNU realpath can't be depended upon to always be available. Simulate it.
# https://stackoverflow.com/questions/3572030/bash-script-absolute-path-with-os-x
safe_realpath() {
  [[ $1 = /* ]] && echo "$1" || echo "$PWD/${1#./}"
}

SITE_DIRECTORY_REALPATH=$(safe_realpath "$SITE_DIRECTORY")

# Prevent the user from losing work in case the site directory already exists.
if test -e "$SITE_DIRECTORY"; then
  cat << DANGER

$(printf "\e[1;41m DANGER! \e[0m \e[33m")"$SITE_DIRECTORY_REALPATH" already exists.
$(printf "\e[1;41m         \e[0m")
$(printf "\e[1;41m         \e[0m") If you destroy it, any changes to the Automatic Updates module inside it will be lost forever.
$(printf "\e[1;41m         \e[0m") Consider moving the directory to another location as a backup instead.
$(printf "\e[1;41m         \e[0m")
$(printf "\e[1;41m         \e[0m") Otherwise, if you know what you're doing and still want to continue, make sure any changes you want to
$(printf "\e[1;41m         \e[0m") keep have been committed and pushed to an appropriate remote. Then delete the directory and try again:
$(printf "\e[1;41m         \e[0m")
$(printf "\e[1;41m         \e[0m") rm -rf "$SITE_DIRECTORY_REALPATH"

DANGER
  exit 1
fi

# Prompt for confirmation.
cat << WARNING
You are about to create an Automatic Updates development environment at "$SITE_DIRECTORY". This will download
as much as 400 MB of data and take approximately one minute to complete, depending on your Internet connection.

WARNING
read -p "Do you want to continue? [yN] " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
  # Exit from a function or non-interactive shell but not an interactive one.
  [[ "$0" = "$BASH_SOURCE" ]] && exit 1 || return 1
fi
echo

# Clone Drupal core.
git clone \
  https://git.drupalcode.org/project/drupal.git \
  --branch "$DRUPAL_CORE_BRANCH" \
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
sites
# Composer libraries
vendor
" | tee -a .git/info/exclude

# Clone the Automatic Updates repo into place. (It will still be
# `composer require`d below to bring in its dependencies.)
git clone \
  --branch "$AUTOMATIC_UPDATES_BRANCH" -- \
  https://git.drupalcode.org/project/automatic_updates.git \
  modules/automatic_updates

# Tell Composer to look for the package in the local clone. This is done rather
# than MERELY cloning the module so that the composer.json of the code under
# development is actually exercised and dependencies don't have to be added
# manually.
composer config \
  repositories.automatic_updates \
  path \
  modules/automatic_updates

# Prevent Composer from symlinking path repositories by setting their "symlink"
# option to FALSE in composer.json.
JSON=$(sed 's/"type": "path"/"type": "path", "options": {"symlink": false}/g' composer.json)
echo "$JSON" > composer.json

# Update the Composer platform PHP requirement.
composer config platform.php 7.4.0

# Require the module using the checked out dev branch, ignoring the PHP version
# requirement.
composer require \
  --no-ansi \
  drupal/automatic_updates:dev-"$AUTOMATIC_UPDATES_BRANCH"

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

         Web root: $SITE_DIRECTORY_REALPATH

   - Make and commit code changes to the module repository below. Changes made
     anywhere else will not be captured.

         Module repo: $SITE_DIRECTORY_REALPATH/modules/automatic_updates

  For information on creating issue forks and merge requests see
  https://www.drupal.org/docs/develop/git/using-git-to-contribute-to-drupal/creating-issue-forks-and-merge-requests

$(printf "\e[1;34m")================================================================================

DONE
