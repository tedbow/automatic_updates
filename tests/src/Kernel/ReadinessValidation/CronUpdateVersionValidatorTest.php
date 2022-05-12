<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessValidation;

use Drupal\automatic_updates\CronUpdater;
use Drupal\package_manager\ValidationResult;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;
use Drupal\Tests\package_manager\Traits\PackageManagerBypassTestTrait;

/**
 * @group automatic_updates
 */
class CronUpdateVersionValidatorTest extends AutomaticUpdatesKernelTestBase {

  use PackageManagerBypassTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates'];

  /**
   * Data provider for ::testValidationSkippedIfCronUpdatesDisabled().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerValidationSkippedIfCronUpdatesDisabled(): array {
    $unstable_current_version = [
      ValidationResult::createError([
        'Drupal cannot be automatically updated during cron from its current version, 9.7.0-alpha1, because Automatic Updates only supports updating from stable versions during cron.',
      ]),
    ];
    return [
      'disabled' => [
        CronUpdater::DISABLED,
        [],
      ],
      'security only' => [
        CronUpdater::SECURITY,
        $unstable_current_version,
      ],
      'all' => [
        CronUpdater::ALL,
        $unstable_current_version,
      ],
    ];
  }

  /**
   * Tests that validation is skipped if cron updates are disabled.
   *
   * @param string $cron_setting
   *   The value of the automatic_updates.settings:cron config setting.
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results.
   *
   * @dataProvider providerValidationSkippedIfCronUpdatesDisabled
   */
  public function testValidationSkippedIfCronUpdatesDisabled(string $cron_setting, array $expected_results): void {
    // Set the currently installed version of core to a version that cannot be
    // automatically updated, and will always trigger a validation error. This
    // way, we can be certain that validation only happens if cron updates are
    // enabled.
    $this->setCoreVersion('9.7.0-alpha1');
    $this->config('automatic_updates.settings')
      ->set('cron', $cron_setting)
      ->save();

    $this->assertCheckerResultsFromManager($expected_results, TRUE);
    $this->container->get('cron')->run();
    $this->assertUpdateStagedTimes(0);
  }

}
