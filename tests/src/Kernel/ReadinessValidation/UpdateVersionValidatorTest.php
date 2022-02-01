<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessValidation;

use Drupal\automatic_updates\CronUpdater;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\package_manager\Exception\StageValidationException;
use Drupal\package_manager\ValidationResult;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;
use Drupal\Tests\automatic_updates\Kernel\TestCronUpdater;
use Drupal\Tests\package_manager\Traits\PackageManagerBypassTestTrait;
use Psr\Log\Test\TestLogger;

/**
 * @covers \Drupal\automatic_updates\Validator\UpdateVersionValidator
 *
 * @group automatic_updates
 */
class UpdateVersionValidatorTest extends AutomaticUpdatesKernelTestBase {

  use PackageManagerBypassTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'automatic_updates',
    'package_manager',
    'package_manager_bypass',
  ];

  /**
   * Tests an update version that is same major & minor version as the current.
   */
  public function testNoMajorOrMinorUpdates(): void {
    $this->setCoreVersion('9.8.0');
    $this->config('automatic_updates.settings')
      ->set('cron', CronUpdater::DISABLED)
      ->save();
    $this->assertCheckerResultsFromManager([], TRUE);
  }

  /**
   * Tests an update version that is a different major version than the current.
   */
  public function testMajorUpdates(): void {
    $this->setCoreVersion('8.9.1');
    $result = ValidationResult::createError([
      'Drupal cannot be automatically updated from its current version, 8.9.1, to the recommended version, 9.8.2, because automatic updates from one major version to another are not supported.',
    ]);
    $this->assertCheckerResultsFromManager([$result], TRUE);
  }

  /**
   * Data provider for ::testMinorUpdates().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerMinorUpdates(): array {
    $update_disallowed = ValidationResult::createError([
      'Drupal cannot be automatically updated from its current version, 9.7.1, to the recommended version, 9.8.1, because automatic updates from one minor version to another are not supported.',
    ]);
    $cron_update_disallowed = ValidationResult::createError([
      'Drupal cannot be automatically updated from its current version, 9.7.1, to the recommended version, 9.8.1, because automatic updates from one minor version to another are not supported during cron.',
    ]);

    return [
      'cron disabled, minor updates not allowed' => [
        FALSE,
        CronUpdater::DISABLED,
        [$update_disallowed],
      ],
      'cron disabled, minor updates allowed' => [
        TRUE,
        CronUpdater::DISABLED,
        [],
      ],
      'security updates during cron, minor updates not allowed' => [
        FALSE,
        CronUpdater::SECURITY,
        [$update_disallowed],
      ],
      'security updates during cron, minor updates allowed' => [
        TRUE,
        CronUpdater::SECURITY,
        [$cron_update_disallowed],
      ],
      'cron enabled, minor updates not allowed' => [
        FALSE,
        CronUpdater::ALL,
        [$update_disallowed],
      ],
      'cron enabled, minor updates allowed' => [
        TRUE,
        CronUpdater::ALL,
        [$cron_update_disallowed],
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
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The validation results that should be returned from by the validation
   *   manager, and logged if cron updates are enabled.
   *
   * @dataProvider providerMinorUpdates
   */
  public function testMinorUpdates(bool $allow_minor_updates, string $cron_setting, array $expected_results): void {
    $this->config('automatic_updates.settings')
      ->set('allow_core_minor_updates', $allow_minor_updates)
      ->set('cron', $cron_setting)
      ->save();

    // In order to test what happens when only security updates are enabled
    // during cron (the default behavior), ensure that the latest available
    // release is a security update.
    $this->setReleaseMetadata(__DIR__ . '/../../../fixtures/release-history/drupal.9.8.1-security.xml');

    $this->setCoreVersion('9.7.1');
    $this->assertCheckerResultsFromManager($expected_results, TRUE);

    $logger = new TestLogger();
    $this->container->get('logger.factory')
      ->get('automatic_updates')
      ->addLogger($logger);

    $this->container->get('cron')->run();

    // If cron updates are disabled, the update shouldn't have been started and
    // nothing should have been logged.
    if ($cron_setting === CronUpdater::DISABLED) {
      $this->assertUpdateStagedTimes(0);
      $this->assertEmpty($logger->records);
    }
    // If cron updates are enabled, the validation errors have been logged, and
    // the update shouldn't have been started.
    elseif ($expected_results) {
      $this->assertUpdateStagedTimes(0);

      // An exception exactly like this one should have been thrown by
      // CronUpdater::dispatch(), and subsequently caught, formatted as HTML,
      // and logged.
      $exception = new StageValidationException($expected_results, 'Unable to complete the update because of errors.');
      $log_message = TestCronUpdater::formatValidationException($exception);
      $this->assertTrue($logger->hasRecord($log_message, RfcLogLevel::ERROR));
    }
    // If cron updates are enabled and no validation errors were expected, the
    // update should have started and nothing should have been logged.
    else {
      $this->assertUpdateStagedTimes(1);
      $this->assertEmpty($logger->records);
    }
  }

  /**
   * Tests an update version that is a lower version than the current.
   */
  public function testDowngrading(): void {
    $this->setCoreVersion('9.8.3');
    $result = ValidationResult::createError(['Update version 9.8.2 is lower than 9.8.3, downgrading is not supported.']);
    $this->assertCheckerResultsFromManager([$result], TRUE);
  }

  /**
   * Tests a current version that is a dev version.
   */
  public function testUpdatesFromDevVersion(): void {
    $this->setCoreVersion('9.8.0-dev');
    $result = ValidationResult::createError(['Drupal cannot be automatically updated from its current version, 9.8.0-dev, to the recommended version, 9.8.2, because automatic updates from a dev version to any other version are not supported.']);
    $this->assertCheckerResultsFromManager([$result], TRUE);
  }

  /**
   * Tests a cron update two patch releases ahead of the current version.
   */
  public function testCronUpdateTwoPatchReleasesAhead(): void {
    $this->setCoreVersion('9.8.0');
    $cron = $this->container->get('cron');
    $config = $this->config('automatic_updates.settings');

    $logger = new TestLogger();
    $this->container->get('logger.factory')
      ->get('automatic_updates')
      ->addLogger($logger);

    // The latest version is two patch releases ahead, so we won't update to it
    // during cron, even if configuration allows it, and this should be flagged
    // as an error during readiness checking. Trying to run the update anyway
    // should raise an error.
    $config->set('cron', CronUpdater::ALL)->save();
    $result = ValidationResult::createError(['Drupal cannot be automatically updated during cron from its current version, 9.8.0, to the recommended version, 9.8.2, because Automatic Updates only supports 1 patch version update during cron.']);
    $this->assertCheckerResultsFromManager([$result], TRUE);
    $cron->run();
    $this->assertUpdateStagedTimes(0);
    $this->assertTrue($logger->hasRecord("<h2>Unable to complete the update because of errors.</h2>Drupal cannot be automatically updated during cron from its current version, 9.8.0, to the recommended version, 9.8.2, because Automatic Updates only supports 1 patch version update during cron.", RfcLogLevel::ERROR));

    // If cron updates are totally disabled, there's no problem here and no
    // errors should be raised.
    $config->set('cron', CronUpdater::DISABLED)->save();
    $this->assertCheckerResultsFromManager([], TRUE);

    // Even if cron is configured to allow security updates only, the update
    // will be blocked if it's more than one patch version ahead.
    $config->set('cron', CronUpdater::SECURITY)->save();
    $cron->run();
    $this->assertUpdateStagedTimes(0);
    $this->assertTrue($logger->hasRecord("<h2>Unable to complete the update because of errors.</h2>Drupal cannot be automatically updated during cron from its current version, 9.8.0, to the recommended version, 9.8.2, because Automatic Updates only supports 1 patch version update during cron.", RfcLogLevel::ERROR));
  }

  /**
   * Tests a cron update one patch release ahead of the current version.
   */
  public function testCronUpdateOnePatchReleaseAhead(): void {
    $cron = $this->container->get('cron');
    $this->config('automatic_updates.settings')
      ->set('cron', CronUpdater::ALL)
      ->save();
    $this->assertCheckerResultsFromManager([], TRUE);
    $cron->run();
    $this->assertUpdateStagedTimes(1);
  }

  /**
   * Tests a cron update where the current version is not stable.
   */
  public function testCronUpdateFromUnstableVersion(): void {
    $this->setCoreVersion('9.8.0-alpha1');
    $this->config('automatic_updates.settings')
      ->set('cron', CronUpdater::ALL)
      ->save();
    $logger = new TestLogger();
    $this->container->get('logger.factory')
      ->get('automatic_updates')
      ->addLogger($logger);
    $message = 'Drupal cannot be automatically updated during cron from its current version, 9.8.0-alpha1, because Automatic Updates only supports updating from stable versions during cron.';
    $result = ValidationResult::createError([$message]);
    $this->assertCheckerResultsFromManager([$result], TRUE);

    $this->container->get('cron')->run();
    $this->assertUpdateStagedTimes(0);
    $this->assertTrue($logger->hasRecord("<h2>Unable to complete the update because of errors.</h2>$message", RfcLogLevel::ERROR));
  }

}
