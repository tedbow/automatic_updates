<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessChecker;

use Drupal\automatic_updates\ReadinessChecker\MissingProjectInfo;
use DrupalFinder\DrupalFinder;
use Drupal\Core\Extension\ExtensionList;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests missing project info readiness checking.
 *
 * @group automatic_updates
 */
class MissingProjectInfoTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'automatic_updates',
  ];

  /**
   * Tests pending db updates readiness checks.
   */
  public function testMissingProjectInfo() {
    // The checker should always have messages on the testbot, since project
    // info is added by the packager.
    $messages = $this->container->get('automatic_updates.missing_project_info')->run();
    $this->assertNotEmpty($messages);

    // Now test with a some dummy info data that won't cause any issues.
    $drupal_finder = new DrupalFinder();
    $extension_list = $this->createMock(ExtensionList::class);
    $messages = (new TestMissingProjectInfo($drupal_finder, $extension_list, $extension_list, $extension_list))->run();
    $this->assertEmpty($messages);
  }

}

/**
 * Class TestMissingProjectInfo.
 */
class TestMissingProjectInfo extends MissingProjectInfo {

  /**
   * {@inheritdoc}
   */
  protected function getInfos($extension_type) {
    $infos = [];
    if ($extension_type === 'modules') {
      $infos['system'] = [
        'name' => 'System',
        'type' => 'module',
        'description' => 'Handles general site configuration for administrators.',
        'package' => 'Core',
        'version' => 'VERSION',
        'project' => 'drupal',
        'core' => '8.x',
        'required' => 'true',
        'configure' => 'system.admin_config_system',
        'dependencies' => [],
      ];
    }
    return $infos;
  }

}
