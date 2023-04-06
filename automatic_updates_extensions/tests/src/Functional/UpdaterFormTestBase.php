<?php

declare(strict_types = 1);

namespace Drupal\Tests\automatic_updates_extensions\Functional;

use Drupal\fixture_manipulator\ActiveFixtureManipulator;
use Drupal\package_manager\PathLocator;
use Drupal\Tests\automatic_updates_extensions\Traits\FormTestTrait;
use Drupal\Tests\automatic_updates\Functional\UpdaterFormTestBase as UpdaterFormFunctionalTestBase;

/**
 * Base class for functional tests of updater form.
 *
 * @internal
 */
abstract class UpdaterFormTestBase extends UpdaterFormFunctionalTestBase {

  use FormTestTrait;

  /**
   * The path of the test project's active directory.
   *
   * @var string
   */
  private $activeDir;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'automatic_updates_extensions',
    'semver_test',
    'aaa_update_test',
    'automatic_updates_extensions_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->activeDir = $this->container->get(PathLocator::class)->getProjectRoot();
    (new ActiveFixtureManipulator())
      ->addPackage([
        'name' => 'drupal/semver_test',
        'version' => '8.1.0',
        'type' => 'drupal-module',
      ])
      ->addPackage([
        'name' => 'drupal/aaa_update_test',
        'version' => '2.0.0',
        'type' => 'drupal-module',
      ])
      ->addPackage([
        'name' => 'drupal/automatic_updates_extensions_test_theme',
        'version' => '2.0.0',
        'type' => 'drupal-theme',
      ])
      ->commitChanges();
    $this->drupalPlaceBlock('local_tasks_block', ['primary' => TRUE]);
  }

  /**
   * Sets installed project version.
   *
   * @todo Remove this function with use of the trait from the Update module in
   *   https://drupal.org/i/3348234.
   */
  protected function setProjectInstalledVersion($project_versions): void {
    $this->config('update.settings')
      ->set('fetch.url', $this->baseUrl . '/test-release-history')
      ->save();
    $system_info = [];
    foreach ($project_versions as $project_name => $version) {
      $system_info[$project_name] = [
        'project' => $project_name,
        'version' => $version,
        'hidden' => FALSE,
      ];
    }
    $system_info['drupal'] = [
      'project' => 'drupal',
      'version' => '8.0.0',
      'hidden' => FALSE,
    ];
    $this->config('update_test.settings')
      ->set('system_info', $system_info)
      ->save();
  }

  /**
   * Asserts the table shows the updates.
   *
   * @param string $expected_project_title
   *   The expected project title.
   * @param string $expected_installed_version
   *   The expected installed version.
   * @param string $expected_target_version
   *   The expected target version.
   * @param int $row
   *   The row number.
   */
  protected function assertTableShowsUpdates(string $expected_project_title, string $expected_installed_version, string $expected_target_version, int $row = 1): void {
    $this->assertUpdateTableRow($this->assertSession(), $expected_project_title, $expected_installed_version, $expected_target_version, $row);
  }

  /**
   * Asserts the form shows no updates.
   */
  protected function assertNoUpdates(): void {
    $assert = $this->assertSession();
    $assert->buttonNotExists('Update');
    $assert->pageTextContains('There are no available updates.');
  }

  /**
   * {@inheritdoc}
   */
  protected function checkForUpdates(): void {
    $this->drupalGet('/admin/modules/automatic-update-extensions');
    $this->clickLink('Check manually');
    $this->checkForMetaRefresh();
  }

}
