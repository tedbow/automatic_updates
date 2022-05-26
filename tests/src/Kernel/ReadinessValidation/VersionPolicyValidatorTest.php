<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessValidation;

use Drupal\automatic_updates\CronUpdater;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Exception\StageException;
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
      // Updating from a dev, alpha, beta, or RC release is not allowed during
      // cron. The first case is a control to prove that a legitimate
      // patch-level update from a stable release never raises a readiness
      // error.
      'stable release installed' => [
        '9.8.0',
        "$metadata_dir/drupal.9.8.1-security.xml",
        [CronUpdater::DISABLED, CronUpdater::SECURITY, CronUpdater::ALL],
        [],
      ],
      // This case proves that updating from a dev snapshot is never allowed,
      // regardless of configuration.
      'dev snapshot installed' => [
        '9.8.0-dev',
        "$metadata_dir/drupal.9.8.1-security.xml",
        [CronUpdater::DISABLED, CronUpdater::SECURITY, CronUpdater::ALL],
        [
          $this->createValidationResult('9.8.0-dev', '9.8.1', [
            'Drupal cannot be automatically updated from the installed version, 9.8.0-dev, because automatic updates from a dev version to any other version are not supported.',
          ]),
        ],
      ],
      // The next six cases prove that updating from an alpha, beta, or RC
      // release raises a readiness error if unattended updates are enabled.
      'alpha installed, cron disabled' => [
        '9.8.0-alpha1',
        "$metadata_dir/drupal.9.8.1-security.xml",
        [CronUpdater::DISABLED],
        [],
      ],
      'alpha installed, cron enabled' => [
        '9.8.0-alpha1',
        "$metadata_dir/drupal.9.8.1-security.xml",
        [CronUpdater::SECURITY, CronUpdater::ALL],
        [
          $this->createValidationResult('9.8.0-alpha1', '9.8.1', [
            'Drupal cannot be automatically updated during cron from its current version, 9.8.0-alpha1, because Automatic Updates only supports updating from stable versions during cron.',
          ]),
        ],
      ],
      'beta installed, cron disabled' => [
        '9.8.0-beta2',
        "$metadata_dir/drupal.9.8.1-security.xml",
        [CronUpdater::DISABLED],
        [],
      ],
      'beta installed, cron enabled' => [
        '9.8.0-beta2',
        "$metadata_dir/drupal.9.8.1-security.xml",
        [CronUpdater::SECURITY, CronUpdater::ALL],
        [
          $this->createValidationResult('9.8.0-beta2', '9.8.1', [
            'Drupal cannot be automatically updated during cron from its current version, 9.8.0-beta2, because Automatic Updates only supports updating from stable versions during cron.',
          ]),
        ],
      ],
      'rc installed, cron disabled' => [
        '9.8.0-rc3',
        "$metadata_dir/drupal.9.8.1-security.xml",
        [CronUpdater::DISABLED],
        [],
      ],
      'rc installed, cron enabled' => [
        '9.8.0-rc3',
        "$metadata_dir/drupal.9.8.1-security.xml",
        [CronUpdater::SECURITY, CronUpdater::ALL],
        [
          $this->createValidationResult('9.8.0-rc3', '9.8.1', [
            'Drupal cannot be automatically updated during cron from its current version, 9.8.0-rc3, because Automatic Updates only supports updating from stable versions during cron.',
          ]),
        ],
      ],
      // These two cases prove that, if only security updates are allowed
      // during cron, a readiness error is raised if the next available release
      // is not a security release.
      'update to normal release allowed' => [
        '9.8.1',
        "$metadata_dir/drupal.9.8.2.xml",
        [CronUpdater::DISABLED, CronUpdater::ALL],
        [],
      ],
      'update to normal release, security only in cron' => [
        '9.8.1',
        "$metadata_dir/drupal.9.8.2.xml",
        [CronUpdater::SECURITY],
        [
          $this->createValidationResult('9.8.1', '9.8.2', [
            'Drupal cannot be automatically updated during cron from 9.8.1 to 9.8.2 because 9.8.2 is not a security release.',
          ]),
        ],
      ],
      // These three cases prove that updating from an unsupported minor version
      // will raise a readiness error if unattended updates are enabled.
      // Furthermore, if an error is raised, the messaging will vary depending
      // on whether attended updates across minor versions are allowed. (Note
      // that the target version will not be automatically detected because the
      // release metadata used in these cases doesn't have any 9.7.x releases.)
      'update from unsupported minor, cron disabled' => [
        '9.7.1',
        "$metadata_dir/drupal.9.8.1-security.xml",
        [CronUpdater::DISABLED],
        [],
      ],
      'update from unsupported minor, cron enabled, minor updates forbidden' => [
        '9.7.1',
        "$metadata_dir/drupal.9.8.1-security.xml",
        [CronUpdater::SECURITY, CronUpdater::ALL],
        [
          $this->createValidationResult('9.7.1', NULL, [
            'The currently installed version of Drupal core, 9.7.1, is not in a supported minor version. Your site will not be automatically updated during cron until it is updated to a supported minor version.',
            'See the <a href="/admin/reports/updates">available updates page</a> for available updates.',
          ]),
        ],
      ],
      'update from unsupported minor, cron enabled, minor updates allowed' => [
        '9.7.1',
        "$metadata_dir/drupal.9.8.1-security.xml",
        [CronUpdater::SECURITY, CronUpdater::ALL],
        [
          $this->createValidationResult('9.7.1', NULL, [
            'The currently installed version of Drupal core, 9.7.1, is not in a supported minor version. Your site will not be automatically updated during cron until it is updated to a supported minor version.',
            'Use the <a href="/admin/modules/automatic-update">update form</a> to update to a supported version.',
          ]),
        ],
        TRUE,
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
   * @param string[] $cron_modes
   *   The modes for unattended updates. Can contain any of
   *   \Drupal\automatic_updates\CronUpdater::DISABLED,
   *   \Drupal\automatic_updates\CronUpdater::SECURITY, and
   *   \Drupal\automatic_updates\CronUpdater::ALL.
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results.
   * @param bool $allow_minor_updates
   *   (optional) Whether or not attended updates across minor updates are
   *   allowed. Defaults to FALSE.
   *
   * @dataProvider providerReadinessCheck
   */
  public function testReadinessCheck(string $installed_version, string $release_metadata, array $cron_modes, array $expected_results, bool $allow_minor_updates = FALSE): void {
    $this->setCoreVersion($installed_version);
    $this->setReleaseMetadata(['drupal' => $release_metadata]);

    foreach ($cron_modes as $cron_mode) {
      $this->config('automatic_updates.settings')
        ->set('cron', $cron_mode)
        ->set('allow_core_minor_updates', $allow_minor_updates)
        ->save();

      $this->assertCheckerResultsFromManager($expected_results, TRUE);
    }
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
      'valid target, dev snapshot installed' => [
        ['automatic_updates.updater', 'automatic_updates.cron_updater'],
        '9.8.0-dev',
        "$metadata_dir/drupal.9.8.1-security.xml",
        [CronUpdater::SECURITY, CronUpdater::ALL],
        ['drupal' => '9.8.1'],
        [
          $this->createValidationResult('9.8.0-dev', '9.8.1', [
            'Drupal cannot be automatically updated from the installed version, 9.8.0-dev, because automatic updates from a dev version to any other version are not supported.',
          ]),
        ],
      ],
      // The following cases can only happen by explicitly supplying the updater
      // with an invalid target version.
      'downgrade' => [
        ['automatic_updates.updater', 'automatic_updates.cron_updater'],
        '9.8.1',
        "$metadata_dir/drupal.9.8.2.xml",
        [CronUpdater::SECURITY, CronUpdater::ALL],
        ['drupal' => '9.8.0'],
        [
          $this->createValidationResult('9.8.1', '9.8.0', [
            'Update version 9.8.0 is lower than 9.8.1, downgrading is not supported.',
          ]),
        ],
      ],
      'major version upgrade' => [
        ['automatic_updates.updater', 'automatic_updates.cron_updater'],
        '8.9.1',
        "$metadata_dir/drupal.9.8.2.xml",
        [CronUpdater::SECURITY, CronUpdater::ALL],
        ['drupal' => '9.8.2'],
        [
          $this->createValidationResult('8.9.1', '9.8.2', [
            'Drupal cannot be automatically updated from 8.9.1 to 9.8.2 because automatic updates from one major version to another are not supported.',
          ]),
        ],
      ],
      'unsupported target version' => [
        ['automatic_updates.updater', 'automatic_updates.cron_updater'],
        '9.8.0',
        "$metadata_dir/drupal.9.8.2-unsupported_unpublished.xml",
        [CronUpdater::SECURITY, CronUpdater::ALL],
        ['drupal' => '9.8.1'],
        [
          $this->createValidationResult('9.8.0', '9.8.1', [
            'Cannot update Drupal core to 9.8.1 because it is not in the list of installable releases.',
          ]),
        ],
      ],
      // This case proves that an attended update to a normal non-security
      // release is allowed regardless of how cron is configured...
      'attended update to normal release' => [
        ['automatic_updates.updater'],
        '9.8.1',
        "$metadata_dir/drupal.9.8.2.xml",
        [CronUpdater::SECURITY, CronUpdater::ALL],
        ['drupal' => '9.8.2'],
        [],
      ],
      // ...and these two cases prove that an unattended update to a normal
      // non-security release is only allowed if cron is configured to allow
      // all updates.
      'unattended update to normal release, security only in cron' => [
        ['automatic_updates.cron_updater'],
        '9.8.1',
        "$metadata_dir/drupal.9.8.2.xml",
        [CronUpdater::SECURITY],
        ['drupal' => '9.8.2'],
        [
          $this->createValidationResult('9.8.1', '9.8.2', [
            'Drupal cannot be automatically updated during cron from 9.8.1 to 9.8.2 because 9.8.2 is not a security release.',
          ]),
        ],
      ],
      'unattended update to normal release, all allowed in cron' => [
        ['automatic_updates.cron_updater'],
        '9.8.1',
        "$metadata_dir/drupal.9.8.2.xml",
        [CronUpdater::ALL],
        ['drupal' => '9.8.2'],
        [],
      ],
      // These three cases prove that updating across minor versions of Drupal
      // core is only allowed for attended updates when a specific configuration
      // flag is set.
      'unattended update to next minor' => [
        ['automatic_updates.cron_updater'],
        '9.7.9',
        "$metadata_dir/drupal.9.8.2.xml",
        [CronUpdater::SECURITY, CronUpdater::ALL],
        ['drupal' => '9.8.2'],
        [
          $this->createValidationResult('9.7.9', '9.8.2', [
            'Drupal cannot be automatically updated from 9.7.9 to 9.8.2 because automatic updates from one minor version to another are not supported during cron.',
          ]),
        ],
      ],
      'attended update to next minor not allowed' => [
        ['automatic_updates.updater'],
        '9.7.9',
        "$metadata_dir/drupal.9.8.2.xml",
        [CronUpdater::SECURITY, CronUpdater::ALL],
        ['drupal' => '9.8.2'],
        [
          $this->createValidationResult('9.7.9', '9.8.2', [
            'Drupal cannot be automatically updated from 9.7.9 to 9.8.2 because automatic updates from one minor version to another are not supported.',
          ]),
        ],
      ],
      'attended update to next minor allowed' => [
        ['automatic_updates.updater'],
        '9.7.9',
        "$metadata_dir/drupal.9.8.2.xml",
        [CronUpdater::SECURITY, CronUpdater::ALL],
        ['drupal' => '9.8.2'],
        [],
        TRUE,
      ],
      // If attended updates across minor versions are allowed, it's okay to
      // update from an unsupported minor version.
      'attended update from unsupported minor allowed' => [
        ['automatic_updates.updater'],
        '9.7.9',
        "$metadata_dir/drupal.9.8.1-security.xml",
        [CronUpdater::SECURITY, CronUpdater::ALL],
        ['drupal' => '9.8.1'],
        [],
        TRUE,
      ],
      // Unattended updates to unstable versions are not allowed.
      'unattended update to unstable version' => [
        ['automatic_updates.cron_updater'],
        '9.8.0',
        "$metadata_dir/drupal.9.8.2-older-sec-release.xml",
        [CronUpdater::SECURITY, CronUpdater::ALL],
        ['drupal' => '9.8.1-beta1'],
        [
          $this->createValidationResult('9.8.0', '9.8.1-beta1', [
            'Drupal cannot be automatically updated during cron to 9.8.1-beta1, because Automatic Updates only supports updating to stable versions during cron.',
          ]),
        ],
      ],
      // Unattended updates from an unsupported minor are never allowed, but
      // the messaging will vary depending on whether attended updates across
      // minor versions are allowed.
      'unattended update from unsupported minor, minor updates forbidden' => [
        ['automatic_updates.cron_updater'],
        '9.7.9',
        "$metadata_dir/drupal.9.8.1-security.xml",
        [CronUpdater::SECURITY, CronUpdater::ALL],
        ['drupal' => '9.8.1'],
        [
          $this->createValidationResult('9.7.9', '9.8.1', [
            'The currently installed version of Drupal core, 9.7.9, is not in a supported minor version. Your site will not be automatically updated during cron until it is updated to a supported minor version.',
            'See the <a href="/admin/reports/updates">available updates page</a> for available updates.',
          ]),
        ],
        FALSE,
      ],
      'unattended update from unsupported minor, minor updates allowed' => [
        ['automatic_updates.cron_updater'],
        '9.7.9',
        "$metadata_dir/drupal.9.8.1-security.xml",
        [CronUpdater::SECURITY, CronUpdater::ALL],
        ['drupal' => '9.8.1'],
        [
          $this->createValidationResult('9.7.9', '9.8.1', [
            'The currently installed version of Drupal core, 9.7.9, is not in a supported minor version. Your site will not be automatically updated during cron until it is updated to a supported minor version.',
            'Use the <a href="/admin/modules/automatic-update">update form</a> to update to a supported version.',
          ]),
        ],
        TRUE,
      ],
    ];
  }

  /**
   * Tests validation of explicitly specified target versions.
   *
   * @param string[] $updaters
   *   The IDs of the updater services to test.
   * @param string $installed_version
   *   The installed version of Drupal core.
   * @param string $release_metadata
   *   The path of the core release metadata to serve to the update system.
   * @param string[] $cron_modes
   *   The modes for unattended updates. Can contain
   *   \Drupal\automatic_updates\CronUpdater::SECURITY or
   *   \Drupal\automatic_updates\CronUpdater::ALL.
   * @param string[] $project_versions
   *   The desired project versions that should be passed to the updater.
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results.
   * @param bool $allow_minor_updates
   *   (optional) Whether to allow attended updates across minor versions.
   *   Defaults to FALSE.
   *
   * @dataProvider providerApi
   */
  public function testApi(array $updaters, string $installed_version, string $release_metadata, array $cron_modes, array $project_versions, array $expected_results, bool $allow_minor_updates = FALSE): void {
    $this->setCoreVersion($installed_version);
    $this->setReleaseMetadata(['drupal' => $release_metadata]);

    foreach ($cron_modes as $cron_mode) {
      $this->config('automatic_updates.settings')
        ->set('cron', $cron_mode)
        ->set('allow_core_minor_updates', $allow_minor_updates)
        ->save();

      foreach ($updaters as $updater) {
        /** @var \Drupal\automatic_updates\Updater $updater */
        $updater = $this->container->get($updater);

        try {
          $updater->begin($project_versions);
          // Ensure that we did not, in fact, expect any errors.
          $this->assertEmpty($expected_results);
          // Reset the updater for the next iteration of the loop.
          $updater->destroy();
        }
        catch (StageValidationException $e) {
          $this->assertValidationResultsEqual($expected_results, $e->getResults());
        }
      }
    }
  }

  /**
   * Creates an expected validation result.
   *
   * Results returned from VersionPolicyValidator are always summarized in the
   * same way, so this method ensures that expected validation results are
   * summarized accordingly.
   *
   * @param string $installed_version
   *   The installed version of Drupal core.
   * @param string|null $target_version
   *   The target version of Drupal core, or NULL if it's not known.
   * @param string[] $messages
   *   The error messages that the result should contain.
   *
   * @return \Drupal\package_manager\ValidationResult
   *   A validation error object with the appropriate summary.
   */
  private function createValidationResult(string $installed_version, ?string $target_version, array $messages): ValidationResult {
    if ($target_version) {
      $summary = t('Updating from Drupal @installed_version to @target_version is not allowed.', [
        '@installed_version' => $installed_version,
        '@target_version' => $target_version,
      ]);
    }
    else {
      $summary = t('Updating from Drupal @installed_version is not allowed.', [
        '@installed_version' => $installed_version,
      ]);
    }
    return ValidationResult::createError($messages, $summary);
  }

  /**
   * Tests that an error is raised if there are no stored package versions.
   *
   * This is a contrived situation that should never happen in real life, but
   * just in case it does, we need to be sure that it's an error condition.
   */
  public function testNoStagedPackageVersions(): void {
    // Remove the stored package versions from the updater's metadata.
    $listener = function (PreCreateEvent $event): void {
      /** @var \Drupal\Tests\automatic_updates\Kernel\TestUpdater $updater */
      $updater = $event->getStage();
      $updater->setMetadata('packages', [
        'production' => [],
      ]);
    };
    $this->assertTargetVersionNotDiscoverable($listener);
  }

  /**
   * Tests that an error is raised if no core packages are installed.
   *
   * This is a contrived situation that should never happen in real life, but
   * just in case it does, we need to be sure that it's an error condition.
   */
  public function testNoCorePackagesInstalled(): void {
    // Clear the list of packages in the active directory's installed.json.
    $listener = function (PreCreateEvent $event): void {
      // We should have staged package versions.
      /** @var \Drupal\automatic_updates\Updater $updater */
      $updater = $event->getStage();
      $this->assertNotEmpty($updater->getPackageVersions());

      $active_dir = $this->container->get('package_manager.path_locator')
        ->getProjectRoot();
      $installed = $active_dir . '/vendor/composer/installed.json';
      $this->assertFileIsWritable($installed);
      file_put_contents($installed, '{"packages": []}');
    };
    $this->assertTargetVersionNotDiscoverable($listener);
  }

  /**
   * Asserts that an error is raised if the target version of Drupal is unknown.
   *
   * @param \Closure $listener
   *   A pre-create event listener to run before all validators. This should put
   *   the virtual project and/or updater into a state which will cause
   *   \Drupal\automatic_updates\Validator\VersionPolicyValidator::getTargetVersion()
   *   to throw an exception because the target version of Drupal core is not
   *   known.
   */
  private function assertTargetVersionNotDiscoverable(\Closure $listener): void {
    $this->createTestProject();
    $this->container->get('event_dispatcher')
      ->addListener(PreCreateEvent::class, $listener, PHP_INT_MAX);

    $this->expectException(StageException::class);
    $this->expectExceptionMessage('The target version of Drupal core could not be determined.');
    $this->container->get('automatic_updates.updater')
      ->begin(['drupal' => '9.8.1']);
  }

}
