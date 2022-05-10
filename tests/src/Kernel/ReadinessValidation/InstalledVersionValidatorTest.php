<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessValidation;

use Drupal\automatic_updates\CronUpdater;
use Drupal\package_manager\ValidationResult;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;

/**
 * @covers \Drupal\automatic_updates\Validator\InstalledVersionValidator
 *
 * @group automatic_updates
 */
class InstalledVersionValidatorTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates'];

  /**
   * Tests that the installed version of Drupal is checked for updateability.
   */
  public function testInstalledVersionValidation(): void {
    $this->setCoreVersion('9.8.0-dev');
    // Disable cron to avoid messages from other validators.
    // @see \Drupal\automatic_updates\Validator\CronUpdateVersionValidator
    $this->config('automatic_updates.settings')
      ->set('cron', CronUpdater::DISABLED)
      ->save();

    $result = ValidationResult::createError([
      'Drupal cannot be automatically updated from the installed version, 9.8.0-dev, because automatic updates from a dev version to any other version are not supported.',
    ]);
    $this->assertCheckerResultsFromManager([$result], TRUE);
  }

}
