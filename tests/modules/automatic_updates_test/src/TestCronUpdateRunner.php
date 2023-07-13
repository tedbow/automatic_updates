<?php

namespace Drupal\automatic_updates_test;

use Composer\Autoload\ClassLoader;
use Drupal\automatic_updates\CronUpdateRunner;
use function PHPUnit\Framework\assertCount;

class TestCronUpdateRunner extends CronUpdateRunner {

  /**
   * {}
   */
  protected function getCommandPath(): string {
    // Return the real path of Drush.
    $loaders = ClassLoader::getRegisteredLoaders();
    assertCount(1, $loaders);
    $vendor_path = array_keys($loaders)[0];
    return $vendor_path . '/drush/drush/drush';
  }

}
