<?php

namespace Drupal\Tests\automatic_updates_extensions\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Test to ensure the module is hidden.
 *
 * @group automatic_updates_extensions
 */
class EnsureHiddenTest extends UnitTestCase {

  /**
   * Tests that module is hidden.
   */
  public function testModuleIsHidden() {
    $info = Yaml::parseFile(__DIR__ . '/../../../automatic_updates_extensions.info.yml');
    $this->assertSame(TRUE, $info['hidden']);
  }

}
