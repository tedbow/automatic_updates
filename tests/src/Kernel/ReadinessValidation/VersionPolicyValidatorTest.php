<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessValidation;

use Drupal\automatic_updates\CronUpdater;
use Drupal\package_manager\Exception\StageValidationException;
use Drupal\package_manager\ValidationResult;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;

/**
 * @covers \Drupal\automatic_updates\Validator\VersionPolicyValidator
 *
 * @group automatic_updates
 */
class VersionPolicyValidatorTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates'];

  /**
   * Data provider for ::testReadinessCheck().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerReadinessCheck(): array {
    $metadata_dir = __DIR__ . '/../../../fixtures/release-history';

    return [
      'dev snapshot installed, cron disabled' => [
        '9.8.0-dev',
        "$metadata_dir/drupal.9.8.1-security.xml",
        CronUpdater::DISABLED,
        [
          ValidationResult::createError([
            'Drupal cannot be automatically updated from the installed version, 9.8.0-dev, because automatic updates from a dev version to any other version are not supported.',
          ]),
        ],
      ],
      'dev snapshot installed, security only in cron' => [
        '9.8.0-dev',
        "$metadata_dir/drupal.9.8.1-security.xml",
        CronUpdater::SECURITY,
        [
          ValidationResult::createError([
            'Drupal cannot be automatically updated from the installed version, 9.8.0-dev, because automatic updates from a dev version to any other version are not supported.',
          ]),
        ],
      ],
      'dev snapshot installed, all allowed in cron' => [
        '9.8.0-dev',
        "$metadata_dir/drupal.9.8.1-security.xml",
        CronUpdater::ALL,
        [
          ValidationResult::createError([
            'Drupal cannot be automatically updated from the installed version, 9.8.0-dev, because automatic updates from a dev version to any other version are not supported.',
          ]),
        ],
      ],
      'stable release installed, cron disabled' => [
        '9.8.0',
        "$metadata_dir/drupal.9.8.1-security.xml",
        CronUpdater::DISABLED,
        [],
      ],
    ];
  }

  /**
   * Tests target version validation during readiness checks.
   *
   * @param string $installed_version
   *   The installed version of Drupal core.
   * @param string $release_metadata
   *   The path of the core release metadata to serve to the update system.
   * @param string $cron_mode
   *   The mode for unattended updates. Can be either
   *   \Drupal\automatic_updates\CronUpdater::SECURITY or
   *   \Drupal\automatic_updates\CronUpdater::ALL.
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results.
   *
   * @dataProvider providerReadinessCheck
   */
  public function testReadinessCheck(string $installed_version, string $release_metadata, string $cron_mode, array $expected_results): void {
    $this->setCoreVersion($installed_version);
    $this->setReleaseMetadata(['drupal' => $release_metadata]);
    $this->config('automatic_updates.settings')
      ->set('cron', $cron_mode)
      ->save();

    $this->assertCheckerResultsFromManager($expected_results, TRUE);
  }

  /**
   * Data provider for ::testApi().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerApi(): array {
    $metadata_dir = __DIR__ . '/../../../fixtures/release-history';

    return [
      'attended, valid target, dev snapshot installed, security only in cron' => [
        'automatic_updates.updater',
        '9.8.0-dev',
        "$metadata_dir/drupal.9.8.1-security.xml",
        CronUpdater::SECURITY,
        ['drupal' => '9.8.1'],
        [
          ValidationResult::createError([
            'Drupal cannot be automatically updated from the installed version, 9.8.0-dev, because automatic updates from a dev version to any other version are not supported.',
          ]),
        ],
      ],
      'attended, valid target, dev snapshot installed, all allowed in cron' => [
        'automatic_updates.updater',
        '9.8.0-dev',
        "$metadata_dir/drupal.9.8.1-security.xml",
        CronUpdater::ALL,
        ['drupal' => '9.8.1'],
        [
          ValidationResult::createError([
            'Drupal cannot be automatically updated from the installed version, 9.8.0-dev, because automatic updates from a dev version to any other version are not supported.',
          ]),
        ],
      ],
      'unattended, valid target, dev snapshot installed, security only in cron' => [
        'automatic_updates.cron_updater',
        '9.8.0-dev',
        "$metadata_dir/drupal.9.8.1-security.xml",
        CronUpdater::SECURITY,
        ['drupal' => '9.8.1'],
        [
          ValidationResult::createError([
            'Drupal cannot be automatically updated from the installed version, 9.8.0-dev, because automatic updates from a dev version to any other version are not supported.',
          ]),
        ],
      ],
      'unattended, valid target dev snapshot installed, all allowed in cron' => [
        'automatic_updates.cron_updater',
        '9.8.0-dev',
        "$metadata_dir/drupal.9.8.1-security.xml",
        CronUpdater::ALL,
        ['drupal' => '9.8.1'],
        [
          ValidationResult::createError([
            'Drupal cannot be automatically updated from the installed version, 9.8.0-dev, because automatic updates from a dev version to any other version are not supported.',
          ]),
        ],
      ],
      // The following cases can only happen by explicitly supplying the updater
      // with an invalid target version.
      'attended downgrade, security only in cron' => [
        'automatic_updates.updater',
        '9.8.1',
        "$metadata_dir/drupal.9.8.2.xml",
        CronUpdater::SECURITY,
        ['drupal' => '9.8.0'],
        [
          ValidationResult::createError([
            'Update version 9.8.0 is lower than 9.8.1, downgrading is not supported.',
          ]),
        ],
      ],
      'attended downgrade, all allowed in cron' => [
        'automatic_updates.updater',
        '9.8.1',
        "$metadata_dir/drupal.9.8.2.xml",
        CronUpdater::ALL,
        ['drupal' => '9.8.0'],
        [
          ValidationResult::createError([
            'Update version 9.8.0 is lower than 9.8.1, downgrading is not supported.',
          ]),
        ],
      ],
      'unattended downgrade, security only in cron' => [
        'automatic_updates.cron_updater',
        '9.8.1',
        "$metadata_dir/drupal.9.8.2.xml",
        CronUpdater::SECURITY,
        ['drupal' => '9.8.0'],
        [
          ValidationResult::createError([
            'Update version 9.8.0 is lower than 9.8.1, downgrading is not supported.',
          ]),
        ],
      ],
      'unattended downgrade, all allowed in cron' => [
        'automatic_updates.cron_updater',
        '9.8.1',
        "$metadata_dir/drupal.9.8.2.xml",
        CronUpdater::ALL,
        ['drupal' => '9.8.0'],
        [
          ValidationResult::createError([
            'Update version 9.8.0 is lower than 9.8.1, downgrading is not supported.',
          ]),
        ],
      ],
      'attended major version upgrade, security only in cron' => [
        'automatic_updates.updater',
        '8.9.1',
        "$metadata_dir/drupal.9.8.2.xml",
        CronUpdater::SECURITY,
        ['drupal' => '9.8.2'],
        [
          ValidationResult::createError([
            'Drupal cannot be automatically updated from its current version, 8.9.1, to the recommended version, 9.8.2, because automatic updates from one major version to another are not supported.',
          ]),
        ],
      ],
      'attended major version upgrade, all allowed in cron' => [
        'automatic_updates.updater',
        '8.9.1',
        "$metadata_dir/drupal.9.8.2.xml",
        CronUpdater::ALL,
        ['drupal' => '9.8.2'],
        [
          ValidationResult::createError([
            'Drupal cannot be automatically updated from its current version, 8.9.1, to the recommended version, 9.8.2, because automatic updates from one major version to another are not supported.',
          ]),
        ],
      ],
      'unattended major version upgrade, security only in cron' => [
        'automatic_updates.cron_updater',
        '8.9.1',
        "$metadata_dir/drupal.9.8.2.xml",
        CronUpdater::SECURITY,
        ['drupal' => '9.8.2'],
        [
          ValidationResult::createError([
            'Drupal cannot be automatically updated from its current version, 8.9.1, to the recommended version, 9.8.2, because automatic updates from one major version to another are not supported.',
          ]),
        ],
      ],
      'unattended major version upgrade, all allowed in cron' => [
        'automatic_updates.cron_updater',
        '8.9.1',
        "$metadata_dir/drupal.9.8.2.xml",
        CronUpdater::ALL,
        ['drupal' => '9.8.2'],
        [
          ValidationResult::createError([
            'Drupal cannot be automatically updated from its current version, 8.9.1, to the recommended version, 9.8.2, because automatic updates from one major version to another are not supported.',
          ]),
        ],
      ],
      'attended update to unsupported target version, security only in cron' => [
        'automatic_updates.updater',
        '9.8.0',
        "$metadata_dir/drupal.9.8.2-unsupported_unpublished.xml",
        CronUpdater::SECURITY,
        ['drupal' => '9.8.1'],
        [
          ValidationResult::createError([
            'Cannot update Drupal core to 9.8.1 because it is not in the list of installable releases.',
          ]),
        ],
      ],
      'attended update to unsupported target version, all allowed in cron' => [
        'automatic_updates.updater',
        '9.8.0',
        "$metadata_dir/drupal.9.8.2-unsupported_unpublished.xml",
        CronUpdater::ALL,
        ['drupal' => '9.8.1'],
        [
          ValidationResult::createError([
            'Cannot update Drupal core to 9.8.1 because it is not in the list of installable releases.',
          ]),
        ],
      ],
      'unattended update to unsupported target version, security only in cron' => [
        'automatic_updates.cron_updater',
        '9.8.0',
        "$metadata_dir/drupal.9.8.2-unsupported_unpublished.xml",
        CronUpdater::SECURITY,
        ['drupal' => '9.8.1'],
        [
          ValidationResult::createError([
            'Cannot update Drupal core to 9.8.1 because it is not in the list of installable releases.',
          ]),
        ],
      ],
      'unattended update to unsupported target version, all allowed in cron' => [
        'automatic_updates.cron_updater',
        '9.8.0',
        "$metadata_dir/drupal.9.8.2-unsupported_unpublished.xml",
        CronUpdater::ALL,
        ['drupal' => '9.8.1'],
        [
          ValidationResult::createError([
            'Cannot update Drupal core to 9.8.1 because it is not in the list of installable releases.',
          ]),
        ],
      ],
    ];
  }

  /**
   * Tests validation of explicitly specified target versions.
   *
   * @param string $updater
   *   The ID of the updater srevice to use.
   * @param string $installed_version
   *   The installed version of Drupal core.
   * @param string $release_metadata
   *   The path of the core release metadata to serve to the update system.
   * @param string $cron_mode
   *   The mode for unattended updates. Can be either
   *   \Drupal\automatic_updates\CronUpdater::SECURITY or
   *   \Drupal\automatic_updates\CronUpdater::ALL.
   * @param string[] $project_versions
   *   The desired project versions that should be passed to the updater.
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results.
   *
   * @dataProvider providerApi
   */
  public function testApi(string $updater, string $installed_version, string $release_metadata, string $cron_mode, array $project_versions, array $expected_results): void {
    $this->setCoreVersion($installed_version);
    $this->setReleaseMetadata(['drupal' => $release_metadata]);
    $this->config('automatic_updates.settings')
      ->set('cron', $cron_mode)
      ->save();

    try {
      $this->container->get($updater)->begin($project_versions);
      // Ensure that we did not, in fact, expect any errors.
      $this->assertEmpty($expected_results);
    }
    catch (StageValidationException $e) {
      $this->assertValidationResultsEqual($expected_results, $e->getResults());
    }
  }

}
