<?php

namespace Drupal\Tests\automatic_updates\Kernel\VersionPolicy;

use Drupal\automatic_updates\Validator\VersionPolicy\TargetVersionStable;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;

/**
 * @covers \Drupal\automatic_updates\Validator\VersionPolicy\TargetVersionStable
 *
 * @group automatic_updates
 */
class TargetVersionStableTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates'];

  /**
   * Data provider for ::testTargetVersionStable().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerTargetVersionStable(): array {
    return [
      'stable target version, attended' => [
        'automatic_updates.updater',
        '9.9.0',
        [],
      ],
      'stable target version, unattended' => [
        'automatic_updates.cron_updater',
        '9.9.0',
        [],
      ],
      'dev target version, attended' => [
        'automatic_updates.updater',
        '9.9.0-dev',
        ['Drupal cannot be automatically updated during cron to the recommended version, 9.9.0-dev, because Automatic Updates only supports updating to stable versions during cron.'],
      ],
      'dev target version, unattended' => [
        'automatic_updates.cron_updater',
        '9.9.0-dev',
        ['Drupal cannot be automatically updated during cron to the recommended version, 9.9.0-dev, because Automatic Updates only supports updating to stable versions during cron.'],
      ],
      'alpha target version, attended' => [
        'automatic_updates.updater',
        '9.9.0-alpha3',
        ['Drupal cannot be automatically updated during cron to the recommended version, 9.9.0-alpha3, because Automatic Updates only supports updating to stable versions during cron.'],
      ],
      'alpha target version, unattended' => [
        'automatic_updates.cron_updater',
        '9.9.0-alpha3',
        ['Drupal cannot be automatically updated during cron to the recommended version, 9.9.0-alpha3, because Automatic Updates only supports updating to stable versions during cron.'],
      ],
      'beta target version, attended' => [
        'automatic_updates.updater',
        '9.9.0-beta7',
        ['Drupal cannot be automatically updated during cron to the recommended version, 9.9.0-beta7, because Automatic Updates only supports updating to stable versions during cron.'],
      ],
      'beta target version, unattended' => [
        'automatic_updates.cron_updater',
        '9.9.0-beta7',
        ['Drupal cannot be automatically updated during cron to the recommended version, 9.9.0-beta7, because Automatic Updates only supports updating to stable versions during cron.'],
      ],
      'release candidate target version, attended' => [
        'automatic_updates.updater',
        '9.9.0-rc2',
        ['Drupal cannot be automatically updated during cron to the recommended version, 9.9.0-rc2, because Automatic Updates only supports updating to stable versions during cron.'],
      ],
      'release candidate target version, unattended' => [
        'automatic_updates.cron_updater',
        '9.9.0-rc2',
        ['Drupal cannot be automatically updated during cron to the recommended version, 9.9.0-rc2, because Automatic Updates only supports updating to stable versions during cron.'],
      ],
    ];
  }

  /**
   * Tests that trying to update to a non-stable version raises an error.
   *
   * @param string $updater
   *   The updater service to use.
   * @param string $target_version
   *   The target version of Drupal core.
   * @param string[] $expected_errors
   *   The expected error messages, if any.
   *
   * @dataProvider providerTargetVersionStable
   */
  public function testTargetVersionStable(string $updater, string $target_version, array $expected_errors): void {
    $updater = $this->container->get($updater);
    $rule = new TargetVersionStable();
    $actual_errors = array_map('strval', $rule->validate($updater, '9.8.0', $target_version));
    $this->assertSame($expected_errors, $actual_errors);
  }

}
