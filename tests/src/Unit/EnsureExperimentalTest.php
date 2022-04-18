<?php

namespace Drupal\Tests\automatic_updates\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests to ensure the modules are experimental.
 *
 * @group automatic_updates
 */
class EnsureExperimentalTest extends UnitTestCase {

  /**
   * Tests that the modules are experimental.
   */
  public function testModulesExperimental() {
    $info_files = [
      __DIR__ . '/../../../automatic_updates_extensions/automatic_updates_extensions.info.yml',
      __DIR__ . '/../../../automatic_updates.info.yml',
      __DIR__ . '/../../../package_manager/package_manager.info.yml',

    ];
    foreach ($info_files as $info_file) {
      $info = Yaml::parseFile($info_file);
      $this->assertSame('experimental', $info['lifecycle']);
    }
  }

}
