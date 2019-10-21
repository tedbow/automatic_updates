#!/bin/bash

rm -fv composer.lock
composer install --no-dev -o
composer dump-autoload --no-dev --classmap-authoritative
rm -rfv vendor/drupal/php-signify/sh
rm -rfv vendor/drupal/php-signify/tests
rm -rfv vendor/paragonie/random_compat/other
rm -rfv vendor/paragonie/random_compat/tests
find ./vendor -name .git -type d -prune -exec rm -rf {} \;
find ./vendor  -type f -name '.*' -delete
