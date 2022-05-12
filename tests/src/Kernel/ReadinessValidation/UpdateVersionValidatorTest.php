<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessValidation;

use Drupal\automatic_updates\CronUpdater;
use Drupal\package_manager\ValidationResult;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;
use Drupal\Tests\package_manager\Traits\PackageManagerBypassTestTrait;
use Psr\Log\Test\TestLogger;

/**
 * @group automatic_updates
 */
class UpdateVersionValidatorTest extends AutomaticUpdatesKernelTestBase {

  use PackageManagerBypassTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates'];

  /**
   * The logger for cron updates.
   *
   * @var \Psr\Log\Test\TestLogger
   */
  private $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->logger = new TestLogger();
    $this->container->get('logger.factory')
      ->get('automatic_updates')
      ->addLogger($this->logger);
  }

  /**
   * Data provider for all possible cron update frequencies.
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerOnCurrentVersion(): array {
    return [
      'disabled' => [CronUpdater::DISABLED],
      'security' => [CronUpdater::SECURITY],
      'all' => [CronUpdater::ALL],
    ];
  }

  /**
   * Tests an update version that is same major & minor version as the current.
   *
   * @param string $cron_setting
   *   The value of the automatic_updates.settings:cron config setting.
   *
   * @dataProvider providerOnCurrentVersion
   */
  public function testOnCurrentVersion(string $cron_setting): void {
    $this->setCoreVersion('9.8.2');
    $this->config('automatic_updates.settings')
      ->set('cron', $cron_setting)
      ->save();

    $this->assertCheckerResultsFromManager([], TRUE);
    $this->container->get('cron')->run();
    $this->assertUpdateStagedTimes(0);
  }

  /**
   * Data provider for ::testMinorUpdates().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerMinorUpdates(): array {
    return [
      'cron disabled, minor updates not allowed' => [
        FALSE,
        CronUpdater::DISABLED,
      ],
      'cron disabled, minor updates allowed' => [
        TRUE,
        CronUpdater::DISABLED,
      ],
      'security updates during cron, minor updates not allowed' => [
        FALSE,
        CronUpdater::SECURITY,
      ],
      'security updates during cron, minor updates allowed' => [
        TRUE,
        CronUpdater::SECURITY,
      ],
      'cron enabled, minor updates not allowed' => [
        FALSE,
        CronUpdater::ALL,
      ],
      'cron enabled, minor updates allowed' => [
        TRUE,
        CronUpdater::ALL,
      ],
    ];
  }

  /**
   * Tests an update version that is a different minor version than the current.
   *
   * @param bool $allow_minor_updates
   *   Whether or not updates across minor core versions are allowed in config.
   * @param string $cron_setting
   *   Whether cron updates are enabled, and how often; should be one of the
   *   constants in \Drupal\automatic_updates\CronUpdater. This determines which
   *   stage the validator will use; if cron updates are enabled at all,
   *   it will be an instance of CronUpdater.
   *
   * @dataProvider providerMinorUpdates
   */
  public function testMinorUpdates(bool $allow_minor_updates, string $cron_setting): void {
    $this->config('automatic_updates.settings')
      ->set('allow_core_minor_updates', $allow_minor_updates)
      ->set('cron', $cron_setting)
      ->save();

    // In order to test what happens when only security updates are enabled
    // during cron (the default behavior), ensure that the latest available
    // release is a security update.
    $this->setReleaseMetadata(['drupal' => __DIR__ . '/../../../fixtures/release-history/drupal.9.8.1-security.xml']);

    $this->setCoreVersion('9.7.1');
    $this->assertCheckerResultsFromManager([], TRUE);

    $this->container->get('cron')->run();

    $this->assertUpdateStagedTimes(0);
    $this->assertEmpty($this->logger->records);
  }

  /**
   * Data provider for ::testCronUpdateTwoPatchReleasesAhead().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerCronUpdateTwoPatchReleasesAhead(): array {
    return [
      'disabled' => [
        CronUpdater::DISABLED,
        [],
        0,
      ],
      'security only' => [
        CronUpdater::SECURITY,
        [
          ValidationResult::createError([
            'Drupal cannot be automatically updated during cron from its current version, 9.8.0, to the recommended version, 9.8.1, because 9.8.1 is not a security release.',
          ]),
        ],
        0,
      ],
      'all' => [
        CronUpdater::ALL,
        [],
        1,
      ],
    ];
  }

  /**
   * Tests a cron update two patch releases ahead of the current version.
   *
   * @param string $cron_setting
   *   The value of the automatic_updates.settings:cron config setting.
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results, which should be logged as errors if the
   *   update is attempted during cron.
   * @param int $expected_stage_times
   *   The expected number of times the update should have been staged.
   *
   * @dataProvider providerCronUpdateTwoPatchReleasesAhead
   */
  public function testCronUpdateTwoPatchReleasesAhead(string $cron_setting, array $expected_results, int $expected_stage_times): void {
    $this->setCoreVersion('9.8.0');
    $this->config('automatic_updates.settings')
      ->set('cron', $cron_setting)
      ->save();

    $this->assertCheckerResultsFromManager($expected_results, TRUE);
    $this->container->get('cron')->run();
    $this->assertUpdateStagedTimes($expected_stage_times);
  }

  /**
   * Data provider for ::testCronUpdateOnePatchReleaseAhead().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerCronUpdateOnePatchReleaseAhead(): array {
    return [
      'disabled' => [
        CronUpdater::DISABLED,
        FALSE,
      ],
      // The latest release is not a security update, so the update will only
      // happen if cron is updates are allowed for any patch release.
      'security' => [
        CronUpdater::SECURITY,
        FALSE,
      ],
      'all' => [
        CronUpdater::ALL,
        TRUE,
      ],
    ];
  }

  /**
   * Tests a cron update one patch release ahead of the current version.
   *
   * @param string $cron_setting
   *   The value of the automatic_updates.settings:cron config setting.
   * @param bool $will_update
   *   TRUE if the update will occur, otherwise FALSE.
   *
   * @dataProvider providerCronUpdateOnePatchReleaseAhead
   */
  public function testCronUpdateOnePatchReleaseAhead(string $cron_setting, bool $will_update): void {
    $this->config('automatic_updates.settings')
      ->set('cron', $cron_setting)
      ->save();
    if ($cron_setting === CronUpdater::SECURITY) {
      $expected_result = ValidationResult::createError(['Drupal cannot be automatically updated during cron from its current version, 9.8.1, to the recommended version, 9.8.2, because 9.8.2 is not a security release.']);
      $this->assertCheckerResultsFromManager([$expected_result], TRUE);
    }
    else {
      $this->assertCheckerResultsFromManager([], TRUE);
    }
    $this->container->get('cron')->run();
    $this->assertUpdateStagedTimes((int) $will_update);
  }

  /**
   * Data provider for ::testInvalidCronUpdate().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerInvalidCronUpdate(): array {
    $unstable_current_version = ValidationResult::createError([
      'Drupal cannot be automatically updated during cron from its current version, 9.8.0-alpha1, because Automatic Updates only supports updating from stable versions during cron.',
    ]);

    return [
      'unstable current version, cron disabled' => [
        CronUpdater::DISABLED,
        '9.8.0-alpha1',
        // If cron updates are disabled, no error should be flagged, because
        // the validation will be run with the regular updater, not the cron
        // updater.
        [],
      ],
      'unstable current version, security updates allowed' => [
        CronUpdater::SECURITY,
        '9.8.0-alpha1',
        [$unstable_current_version],
      ],
      'unstable current version, all updates allowed' => [
        CronUpdater::ALL,
        '9.8.0-alpha1',
        [$unstable_current_version],
      ],
      // @todo In the 3 following test cases the installed version is not
      //   in a supported branch. These test expectations should be changed or
      //   moved to a new test when we add a validator to check if the installed
      //   version is in a supported branch in https://www.drupal.org/i/3275883.
      'different current major, cron disabled' => [
        CronUpdater::DISABLED,
        '8.9.1',
        [],
      ],
      'different current major, security updates allowed' => [
        CronUpdater::SECURITY,
        '8.9.1',
        [],
      ],
      'different current major, all updates allowed' => [
        CronUpdater::ALL,
        '8.9.1',
        [],
      ],
    ];
  }

  /**
   * Tests invalid version jumps before and during a cron update.
   *
   * @param string $cron_setting
   *   The value of the automatic_updates.settings:cron config setting.
   * @param string $current_core_version
   *   The current core version from which we are updating.
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The validation results, if any, that should be flagged during readiness
   *   checks.
   *
   * @dataProvider providerInvalidCronUpdate
   */
  public function testInvalidCronUpdate(string $cron_setting, string $current_core_version, array $expected_results): void {
    $this->setCoreVersion($current_core_version);
    $this->config('automatic_updates.settings')
      ->set('cron', $cron_setting)
      ->save();

    $this->assertCheckerResultsFromManager($expected_results, TRUE);

    // Try running the update during cron, regardless of the validation results,
    // and ensure it doesn't happen. In certain situations, this will be because
    // of $cron_setting (e.g., if the latest release is a regular patch release
    // but only security updates are allowed during cron); in other situations,
    // it will be due to validation errors being raised when the staging area is
    // created (in which case, we expect the errors to be logged).
    $this->container->get('cron')->run();
    $this->assertUpdateStagedTimes(0);
  }

}
