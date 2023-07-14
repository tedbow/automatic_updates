<?php

declare(strict_types = 1);

namespace Drupal\automatic_updates_test;

use Composer\Autoload\ClassLoader;
use Drupal\automatic_updates\CronUpdateRunner;

class TestCronUpdateRunner extends CronUpdateRunner {

  /**
   * {@inheritdoc}
   */
  protected function getCommandPath(): string {
    // There may be multiple class loaders at work.
    // ClassLoader::getRegisteredLoaders() keeps track of them all, indexed by
    // the path of the vendor directory they load classes from.
    $loaders = ClassLoader::getRegisteredLoaders();

    // If there's only one class loader, we don't need to search for the right
    // one.
    if (count($loaders) === 1) {
      $vendor_path = key($loaders);
    }
    else {
      // To determine which class loader is the one for Drupal's vendor directory,
      // look for the loader whose vendor path starts the same way as the path to
      // this file.
      foreach (array_keys($loaders) as $path) {
        if (str_starts_with(__FILE__, dirname($path))) {
          $vendor_path = $path;
        }
      }
    }
    if (!isset($vendor_path)) {
      // If we couldn't find a match, assume that the first registered class
      // loader is the one we want.
      $vendor_path = key($loaders);
    }

    return $vendor_path . '/drush/drush/drush';
  }

}
