<?php

namespace Drupal\Tests\automatic_updates_extensions\Kernel\Validator;

use Drupal\package_manager\LegacyVersionUtility;
use Drupal\package_manager\Event\PreApplyEvent;
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->disableValidators[] = 'automatic_updates_extensions.validator.packages_installed_with_composer';
    parent::setUp();
  }

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

  /**
   * Data provider for testPreApplyException().
   *
   * @return mixed[][]
   *   The test cases.
   */
  public function providerTestPreApplyException(): array {
    $fixtures_folder = __DIR__ . '/../../../fixtures/update_release_validator';
    return [
      'semver, supported update' => ['semver_test', '8.1.0', '8.1.1', "$fixtures_folder/semver_supported_update_stage", FALSE],
      'semver, update to unsupported branch' => ['semver_test', '8.1.0', '8.2.0', "$fixtures_folder/semver_unsupported_update_stage", TRUE],
      'legacy, supported update' => ['aaa_update_test', '8.x-2.0', '8.x-2.1', "$fixtures_folder/legacy_supported_update_stage", FALSE],
      'legacy, update to unsupported branch' => ['aaa_update_test', '8.x-2.0', '8.x-3.0', "$fixtures_folder/legacy_unsupported_update_stage", TRUE],
    ];
  }

  /**
   * Tests updating to a release during pre-apply.
   *
   * @param string $project
   *   The project to update.
   * @param string $installed_version
   *   The installed version of the project.
   * @param string $target_version
   *   The target version.
   * @param string $stage_dir
   *   Path of fixture stage directory. It will be used as the virtual project's
   *   stage directory.
   * @param bool $error_expected
   *   Whether an error is expected in the update.
   *
   * @dataProvider providerTestPreApplyException
   */
  public function testPreApplyException(string $project, string $installed_version, string $target_version, string $stage_dir, bool $error_expected): void {
    $this->enableModules(['aaa_automatic_updates_test', $project]);

    $module_info = ['version' => $installed_version, 'project' => $project];
    $aaa_automatic_updates_test_info = ['version' => '7.0.0', 'project' => 'aaa_automatic_updates_test'];
    $this->config('update_test.settings')
      ->set("system_info.$project", $module_info)
      ->set("system_info.aaa_automatic_updates_test", $aaa_automatic_updates_test_info)
      ->save();

    // Path of `active.installed.json` file. It will be used as the virtual
    // project's active `vendor/composer/installed.json` file.
    $this->setReleaseMetadata([
      'aaa_automatic_updates_test' => __DIR__ . "/../../../../../package_manager/tests/fixtures/release-history/aaa_automatic_updates_test.9.8.2.xml",
      $project => __DIR__ . "/../../../fixtures/release-history/$project.1.1.xml",
      'drupal' => __DIR__ . '/../../../../../package_manager/tests/fixtures/release-history/drupal.9.8.2.xml',
    ]);
    $active_dir = __DIR__ . '/../../../fixtures/update_release_validator/active';
    $this->copyFixtureFolderToActiveDirectory($active_dir);
    $this->copyFixtureFolderToStageDirectoryOnApply($stage_dir);
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

    // Always updating aaa_automatic_updates_test to 7.0.1(valid release) along
    // with the project provided for test.
    $this->assertUpdateResults(
      [
        'aaa_automatic_updates_test' => '7.0.1',
      ],
      $expected_results,
      PreApplyEvent::class
    );
  }

}
