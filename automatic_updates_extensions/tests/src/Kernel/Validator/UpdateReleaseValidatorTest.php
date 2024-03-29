<?php

namespace Drupal\Tests\automatic_updates_extensions\Kernel\Validator;

use Drupal\package_manager\LegacyVersionUtility;
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
   * Data provider for testPreCreateException().
   *
   * @return mixed[][]
   *   The test cases.
   */
  public function providerTestPreCreateException(): array {
    return [
      'semver, supported update' => ['semver_test', '8.1.0', '8.1.1', FALSE],
      'semver, update to unsupported branch' => ['semver_test', '8.1.0', '8.2.0', TRUE],
      'legacy, supported update' => ['aaa_update_test', '8.x-2.0', '8.x-2.1', FALSE],
      'legacy, update to unsupported branch' => ['aaa_update_test', '8.x-2.0', '8.x-3.0', TRUE],
    ];
  }

  /**
   * Tests updating to a release during pre-create.
   *
   * @param string $project
   *   The project to update.
   * @param string $installed_version
   *   The installed version of the project.
   * @param string $target_version
   *   The target version.
   * @param bool $error_expected
   *   Whether an error is expected in the update.
   *
   * @dataProvider providerTestPreCreateException
   */
  public function testPreCreateException(string $project, string $installed_version, string $target_version, bool $error_expected): void {
    $this->enableModules([$project]);

    $module_info = ['version' => $installed_version, 'project' => $project];
    $this->config('update_test.settings')
      ->set("system_info.$project", $module_info)
      ->save();

    $this->setReleaseMetadata([
      $project => __DIR__ . "/../../../fixtures/release-history/$project.1.1.xml",
      'drupal' => __DIR__ . '/../../../../../package_manager/tests/fixtures/release-history/drupal.9.8.2.xml',
    ]);

    if ($error_expected) {
      $expected_results = [
        ValidationResult::createError(
          ["Project $project to version " . LegacyVersionUtility::convertToSemanticVersion($target_version)],
          t('Cannot update because the following project version is not in the list of installable releases.')
        ),
      ];
    }
    else {
      $expected_results = [];
    }

    $this->assertUpdateResults([$project => $target_version], $expected_results, PreCreateEvent::class);
  }

}
