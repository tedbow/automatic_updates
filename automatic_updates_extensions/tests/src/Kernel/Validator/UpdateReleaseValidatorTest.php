<?php

namespace Drupal\Tests\automatic_updates_extensions\Kernel\Valdiator;

use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\ValidationResult;
use Drupal\Tests\automatic_updates_extensions\Kernel\AutomaticUpdatesExtensionsKernelTestBase;

/**
 * @coversDefaultClass \Drupal\automatic_updates_extensions\Validator\UpdateReleaseValidator
 *
 * @group automatic_updates_extensions
 */
class UpdateReleaseValidatorTest extends AutomaticUpdatesExtensionsKernelTestBase {

  /**
   * Tests updating to a release.
   *
   * @param string $installed_version
   *   The installed version of the project.
   * @param string $update_version
   *   The version to update to.
   * @param bool $error_expected
   *   Whether an error is expected in the update.
   *
   * @dataProvider providerTestRelease
   */
  public function testRelease(string $installed_version, string $update_version, bool $error_expected) {
    $this->enableModules(['semver_test']);
    $module_info = ['version' => $installed_version, 'project' => 'semver_test'];
    $this->config('update_test.settings')
      ->set("system_info.semver_test", $module_info)
      ->save();
    $this->setReleaseMetadataForProjects([
      'semver_test' => __DIR__ . '/../../../fixtures/release-history/semver_test.1.1.xml',
      'drupal' => __DIR__ . '/../../../../../tests/fixtures/release-history/drupal.9.8.2.xml',
    ]);
    if ($error_expected) {
      $expected_results = [
        ValidationResult::createError(
          ["Project semver_test to version $update_version"],
          t('Cannot update because the following project version is not in the list of installable releases.')
        ),
      ];
    }
    else {
      $expected_results = [];
    }

    $this->assertUpdaterResults(['semver_test' => $update_version], $expected_results, PreCreateEvent::class);
  }

  /**
   * Data provider for testRelease().
   *
   * @return array[]
   *   The test cases.
   */
  public function providerTestRelease() {
    return [
      'supported update' => ['8.1.0', '8.1.1', FALSE],
      'update to unsupported branch' => ['8.1.0', '8.2.0', TRUE],
    ];
  }

}
