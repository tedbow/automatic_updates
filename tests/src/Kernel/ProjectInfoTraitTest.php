<?php

namespace Drupal\Tests\automatic_updates\Kernel;

use Drupal\automatic_updates\ProjectInfoTrait;
use Drupal\KernelTests\KernelTestBase;

/**
 * @coversDefaultClass \Drupal\automatic_updates\ProjectInfoTrait
 * @group automatic_updates
 */
class ProjectInfoTraitTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'automatic_updates',
  ];

  /**
   * @covers ::getExtensionVersion
   * @covers ::getProjectName
   * @dataProvider providerInfos
   */
  public function testTrait($expected, $info, $extension_name) {
    $class = new ProjectInfoTestClass();
    $project_name = $class->getProjectName($extension_name, $info);
    $this->assertSame($expected['project'], $project_name);
    $this->assertSame($expected['version'], $class->getExtensionVersion($info + ['project' => $project_name]));
  }

  /**
   * Data provider for testTrait.
   */
  public function providerInfos() {
    $infos['node']['expected'] = [
      'version' => NULL,
      'project' => 'drupal',
    ];
    $infos['node']['info'] = [
      'name' => 'Node',
      'type' => 'module',
      'description' => 'Allows content to be submitted to the site and displayed on pages.',
      'package' => 'Core',
      'version' => '8.8.x-dev',
      'project' => 'drupal',
      'core' => '8.x',
      'configure' => 'entity.node_type.collection',
      'dependencies' => ['drupal:text'],
      'install path' => '',
    ];
    $infos['node']['extension_name'] = 'node';

    $infos['update']['expected'] = [
      'version' => NULL,
      'project' => 'drupal/update',
    ];
    $infos['update']['info'] = [
      'name' => 'Update manager',
      'type' => 'module',
      'description' => 'Checks for available updates, and can securely install or update modules and themes via a web interface.',
      'package' => 'Core',
      'core' => '8.x',
      'configure' => 'update.settings',
      'dependencies' => ['file'],
      'install path' => '',
    ];
    $infos['update']['extension_name'] = 'drupal/update';

    $infos['system']['expected'] = [
      'version' => '8.8.0',
      'project' => 'drupal',
    ];
    $infos['system']['info'] = [
      'name' => 'System',
      'type' => 'module',
      'description' => 'Handles general site configuration for administrators.',
      'package' => 'Core',
      'version' => '8.8.0',
      'project' => 'drupal',
      'core' => '8.x',
      'required' => 'true',
      'configure' => 'system.admin_config_system',
      'dependencies' => [],
      'install path' => '',
    ];
    $infos['system']['extension_name'] = 'system';

    $infos['automatic_updates']['expected'] = [
      'version' => NULL,
      'project' => 'automatic_updates',
    ];
    $infos['automatic_updates']['info'] = [
      'name' => 'Automatic Updates',
      'type' => 'module',
      'description' => 'Display public service announcements and verify readiness for applying automatic updates to the site.',
      'package' => 'Core',
      'core' => '8.x',
      'configure' => 'automatic_updates.settings',
      'dependencies' => ['system', 'update'],
      'install path' => '',
    ];
    $infos['automatic_updates']['extension_name'] = 'automatic_updates';

    // TODO: Investigate switching to this project after stable release in
    // https://www.drupal.org/project/automatic_updates/issues/3061229.
    $infos['ctools']['expected'] = [
      'version' => '8.x-3.2',
      'project' => 'ctools',
    ];
    $infos['ctools']['info'] = [
      'name' => 'Chaos tool suite',
      'type' => 'module',
      'description' => 'Provides a number of utility and helper APIs for Drupal developers and site builders.',
      'package' => 'Core',
      'core' => '8.x',
      'dependencies' => ['system'],
      'install path' => '',
    ];
    $infos['ctools']['extension_name'] = 'ctools';

    return $infos;
  }

}

/**
 * Class ProjectInfoTestClass.
 */
class ProjectInfoTestClass {

  use ProjectInfoTrait {
    getExtensionVersion as getVersion;
    getProjectName as getProject;
  }

  /**
   * {@inheritdoc}
   */
  public function getExtensionVersion(array $info) {
    return $this->getVersion($info);
  }

  /**
   * {@inheritdoc}
   */
  public function getProjectName($extension_name, array $info) {
    return $this->getProject($extension_name, $info);
  }

}
