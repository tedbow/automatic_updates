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
    $this->assertCheckerResultsFromManager([], TRUE);
  }

  /**
   * Tests an update version that is a different major version than the current.
   */
  public function testMajorUpdates(): void {
    $this->setCoreVersion('8.9.1');
    $result = ValidationResult::createError([
      'Drupal cannot be automatically updated from its current version, 8.9.1, to the recommended version, 9.8.1, because automatic updates from one major version to another are not supported.',
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
    $this->setCoreVersion('9.8.2');
    $result = ValidationResult::createError(['Update version 9.8.1 is lower than 9.8.2, downgrading is not supported.']);
    $this->assertCheckerResultsFromManager([$result], TRUE);
  }

  /**
   * Tests a current version that is a dev version.
   */
  public function testUpdatesFromDevVersion(): void {
    $this->setCoreVersion('9.8.0-dev');
    $result = ValidationResult::createError(['Drupal cannot be automatically updated from its current version, 9.8.0-dev, to the recommended version, 9.8.1, because automatic updates from a dev version to any other version are not supported.']);
    $this->assertCheckerResultsFromManager([$result], TRUE);
  }

}
