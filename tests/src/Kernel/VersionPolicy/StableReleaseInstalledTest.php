<?php

namespace Drupal\Tests\automatic_updates\Kernel\VersionPolicy;

use Drupal\automatic_updates\Validator\VersionPolicy\StableReleaseInstalled;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;

/**
 * @covers \Drupal\automatic_updates\Validator\VersionPolicy\StableReleaseInstalled
 *
 * @group automatic_updates
 */
class StableReleaseInstalledTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates'];

  /**
   * Data provider for ::testStableReleaseInstalled().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerStableReleaseInstalled(): array {
    return [
      'stable version installed, attended' => [
        'automatic_updates.updater',
        '9.8.0',
        [],
      ],
      'stable version installed, unattended' => [
        'automatic_updates.cron_updater',
        '9.8.0',
        [],
      ],
      'alpha version installed, attended' => [
        'automatic_updates.updater',
        '9.8.0-alpha3',
        ['Drupal cannot be automatically updated during cron from its current version, 9.8.0-alpha3, because Automatic Updates only supports updating from stable versions during cron.'],
      ],
      'alpha version installed, unattended' => [
        'automatic_updates.cron_updater',
        '9.8.0-alpha3',
        ['Drupal cannot be automatically updated during cron from its current version, 9.8.0-alpha3, because Automatic Updates only supports updating from stable versions during cron.'],
      ],
      'beta version installed, attended' => [
        'automatic_updates.updater',
        '9.8.0-beta7',
        ['Drupal cannot be automatically updated during cron from its current version, 9.8.0-beta7, because Automatic Updates only supports updating from stable versions during cron.'],
      ],
      'beta version installed, unattended' => [
        'automatic_updates.cron_updater',
        '9.8.0-beta7',
        ['Drupal cannot be automatically updated during cron from its current version, 9.8.0-beta7, because Automatic Updates only supports updating from stable versions during cron.'],
      ],
      'release candidate installed, attended' => [
        'automatic_updates.updater',
        '9.8.0-rc2',
        ['Drupal cannot be automatically updated during cron from its current version, 9.8.0-rc2, because Automatic Updates only supports updating from stable versions during cron.'],
      ],
      'release candidate installed, unattended' => [
        'automatic_updates.cron_updater',
        '9.8.0-rc2',
        ['Drupal cannot be automatically updated during cron from its current version, 9.8.0-rc2, because Automatic Updates only supports updating from stable versions during cron.'],
      ],
    ];
  }

  /**
   * Tests that trying to update across minor versions raises an error.
   *
   * @param string $updater
   *   The updater service to use.
   * @param string $installed_version
   *   The installed version of Drupal core.
   * @param string[] $expected_errors
   *   The expected error messages, if any.
   *
   * @dataProvider providerStableReleaseInstalled
   */
  public function testStableReleaseInstalled(string $updater, string $installed_version, array $expected_errors): void {
    $updater = $this->container->get($updater);
    $rule = new StableReleaseInstalled();
    $actual_errors = array_map('strval', $rule->validate($updater, $installed_version, '9.8.1'));
    $this->assertSame($expected_errors, $actual_errors);
  }

}
