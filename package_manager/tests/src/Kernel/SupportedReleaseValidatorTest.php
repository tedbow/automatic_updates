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
        [
          'semver_test' => "$release_fixture_folder/semver_test.1.1.xml",
        ],
        NULL,
        TRUE,
        [
          'name' => "drupal/semver_test",
          'version' => '8.1.1',
          'type' => 'drupal-module',
          'install_path' => NULL,
        ],
        [],
      ],
      'semver, update to unsupported branch' => [
        [
          'semver_test' => "$release_fixture_folder/semver_test.1.1.xml",
        ],
        NULL,
        TRUE,
        [
          'name' => "drupal/semver_test",
          'version' => '8.2.0',
          'type' => 'drupal-module',
          'install_path' => NULL,
        ],
        [
          ValidationResult::createError(['semver_test (drupal/semver_test) 8.2.0'], $summary),
        ],
      ],
      'legacy, supported update' => [
        [
          'aaa_update_test' => "$release_fixture_folder/aaa_update_test.1.1.xml",
        ],
        NULL,
        TRUE,
        [
          'name' => "drupal/aaa_update_test",
          'version' => '2.1.0',
          'type' => 'drupal-module',
          'install_path' => NULL,
        ],
        [],
      ],
      'legacy, update to unsupported branch' => [
        [
          'aaa_update_test' => "$release_fixture_folder/aaa_update_test.1.1.xml",
        ],
        NULL,
        TRUE,
        [
          'name' => "drupal/aaa_update_test",
          'version' => '3.0.0',
          'type' => 'drupal-module',
          'install_path' => NULL,
        ],
        [
          ValidationResult::createError(['aaa_update_test (drupal/aaa_update_test) 3.0.0'], $summary),
        ],
      ],
      'aaa_automatic_updates_test(not in active), update to unsupported branch' => [
        [
          'aaa_automatic_updates_test' => "$release_fixture_folder/aaa_automatic_updates_test.9.8.2.xml",
        ],
        "$fixtures_folder/aaa_automatic_updates_test_unsupported_update_stage",
        FALSE,
        [
          'name' => "drupal/aaa_automatic_updates_test",
          'version' => '7.0.1-dev',
          'type' => 'drupal-module',
          'install_path' => '../../modules/aaa_automatic_updates_test',
        ],
        [
          ValidationResult::createError(['aaa_automatic_updates_test (drupal/aaa_automatic_updates_test) 7.0.1-dev'], $summary),
        ],
      ],
      'aaa_automatic_updates_test(not in active), update to supported branch' => [
        [
          'aaa_automatic_updates_test' => "$release_fixture_folder/aaa_automatic_updates_test.9.8.2.xml",
        ],
        "$fixtures_folder/aaa_automatic_updates_test_supported_update_stage",
        FALSE,
        [
          'name' => "drupal/aaa_automatic_updates_test",
          'version' => '7.0.1',
          'type' => 'drupal-module',
          'install_path' => '../../modules/aaa_automatic_updates_test',
        ],
        [],
      ],
    ];
  }

  /**
   * Tests exceptions when updating to unsupported or insecure releases.
   *
   * @param array $release_metadata
   *   Array of paths of the fake release metadata keyed by project name.
   * @param string|null $stage_fixture_dir
   *   Path of fixture stage directory or NULL. It will be used to fixture files
   *   to virtual stage directory when the project is not in active.
   * @param bool $project_in_active
   *   Whether the project is in the active directory or not.
   * @param array $package
   *   The package that will be added or modified.
   * @param array $expected_results
   *   The expected validation results.
   *
   * @dataProvider providerException
   */
  public function testException(array $release_metadata, ?string $stage_fixture_dir, bool $project_in_active, array $package, array $expected_results): void {
    $this->setReleaseMetadata(['drupal' => __DIR__ . '/../../fixtures/release-history/drupal.9.8.2.xml'] + $release_metadata);
    $active_fixture_dir = __DIR__ . '/../../fixtures/supported_release_validator/active';
    $this->copyFixtureFolderToActiveDirectory($active_fixture_dir);
    if ($stage_fixture_dir) {
      $this->copyFixtureFolderToStageDirectoryOnApply($stage_fixture_dir);
    }

    $listener = function (PreApplyEvent $event) use ($project_in_active, $package): void {
      $stage_dir = $event->getStage()->getStageDirectory();
      // @todo add test coverage for packages that don't start with 'drupal/' in
      //   https://www.drupal.org/node/3321386.
      if (!$project_in_active) {
        $this->addPackage($stage_dir, $package);
      }
      else {
        $this->modifyPackage($stage_dir, $package['name'], [
          'version' => $package['version'],
        ]);
      }
      // We always update this module to prove that the validator will skip this
      // module as it's of type 'drupal-library'.
      // @see \Drupal\package_manager\Validator\SupportedReleaseValidator::checkStagedReleases()
      $this->modifyPackage($stage_dir, "drupal/dependency", [
        'version' => '9.8.1',
      ]);
    };
    $this->container->get('event_dispatcher')
      ->addListener(PreApplyEvent::class, $listener, PHP_INT_MAX);
    $this->assertResults($expected_results, PreApplyEvent::class);
  }

}
