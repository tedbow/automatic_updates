<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\ValidationResult;

/**
 * @coversDefaultClass \Drupal\package_manager\Validator\SupportedReleaseValidator
 *
 * @group package_manager
 */
class SupportedReleaseValidatorTest extends PackageManagerKernelTestBase {

  /**
   * Data provider for testException().
   *
   * @return mixed[][]
   *   The test cases.
   */
  public function providerException(): array {
    $fixtures_folder = __DIR__ . '/../../fixtures/supported_release_validator';
    $release_fixture_folder = __DIR__ . '/../../fixtures/release-history';
    $summary = t('Cannot update because the following project version is not in the list of installable releases.');
    return [
      'semver, supported update' => [
        'semver_test',
        "$release_fixture_folder/semver_test.1.1.xml",
        "$fixtures_folder/semver_supported_update_stage",
        [],
      ],
      'semver, update to unsupported branch' => [
        'semver_test',
        "$release_fixture_folder/semver_test.1.1.xml",
        "$fixtures_folder/semver_unsupported_update_stage",
        [
          ValidationResult::createError(['semver_test (drupal/semver_test) 8.2.0'], $summary),
        ],
      ],
      'legacy, supported update' => [
        'aaa_update_test',
        "$release_fixture_folder/aaa_update_test.1.1.xml",
        "$fixtures_folder/legacy_supported_update_stage",
        [],
      ],
      'legacy, update to unsupported branch' => [
        'aaa_update_test',
        "$release_fixture_folder/aaa_update_test.1.1.xml",
        "$fixtures_folder/legacy_unsupported_update_stage",
        [
          ValidationResult::createError(['aaa_update_test (drupal/aaa_update_test) 3.0.0'], $summary),
        ],
      ],
      'aaa_automatic_updates_test(not in active), update to unsupported branch' => [
        'aaa_automatic_updates_test',
        "$release_fixture_folder/aaa_automatic_updates_test.9.8.2.xml",
        "$fixtures_folder/aaa_automatic_updates_test_unsupported_update_stage",
        [
          ValidationResult::createError(['aaa_automatic_updates_test (drupal/aaa_automatic_updates_test) 7.0.1-dev'], $summary),
        ],
      ],
      'aaa_automatic_updates_test(not in active), update to supported branch' => [
        'aaa_automatic_updates_test',
        "$release_fixture_folder/aaa_automatic_updates_test.9.8.2.xml",
        "$fixtures_folder/aaa_automatic_updates_test_supported_update_stage",
        [],
      ],
    ];
  }

  /**
   * Tests exceptions when updating to unsupported or insecure releases.
   *
   * @param string $project
   *   The project to update.
   * @param string $release_xml
   *   Path of release xml for project.
   * @param string $stage_dir
   *   Path of fixture stage directory. It will be used as the virtual project's
   *   stage directory.
   * @param array $expected_results
   *   The expected validation results.
   *
   * @dataProvider providerException
   */
  public function testException(string $project, string $release_xml, string $stage_dir, array $expected_results): void {
    $this->setReleaseMetadata([
      $project => $release_xml,
      'drupal' => __DIR__ . '/../../fixtures/release-history/drupal.9.8.2.xml',
    ]);
    $active_dir = __DIR__ . '/../../fixtures/supported_release_validator/active';
    $this->copyFixtureFolderToActiveDirectory($active_dir);
    $this->copyFixtureFolderToStageDirectoryOnApply($stage_dir);

    $this->assertResults($expected_results, PreApplyEvent::class);
  }

}
