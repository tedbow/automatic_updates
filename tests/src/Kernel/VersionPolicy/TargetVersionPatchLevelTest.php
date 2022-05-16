<?php

namespace Drupal\Tests\automatic_updates\Kernel\VersionPolicy;

use Drupal\automatic_updates\Validator\VersionPolicy\TargetVersionPatchLevel;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;

/**
 * @covers \Drupal\automatic_updates\Validator\VersionPolicy\TargetVersionPatchLevel
 *
 * @group automatic_updates
 */
class TargetVersionPatchLevelTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates'];

  /**
   * Data provider for ::testTargetVersionPatchLevel().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   *
   * @todo Add scenarios to check what happens if moving between unstable
   *   versions (alpha to alpha, alpha to beta, beta to beta, beta to RC, RC
   *   to RC, RC to stable, the reverse of all these, etc.)
   */
  public function providerTargetVersionPatchLevel(): array {
    return [
      'same version, attended' => [
        'automatic_updates.updater',
        '9.8.1',
        ['Drupal cannot be automatically updated during cron from its current version, 9.8.1, to the recommended version, 9.8.1, because Automatic Updates only supports 1 patch version update during cron.'],
      ],
      'same version, unattended' => [
        'automatic_updates.cron_updater',
        '9.8.1',
        ['Drupal cannot be automatically updated during cron from its current version, 9.8.1, to the recommended version, 9.8.1, because Automatic Updates only supports 1 patch version update during cron.'],
      ],
      '1 patch ahead, attended' => [
        'automatic_updates.updater',
        '9.8.2',
        [],
      ],
      '1 patch ahead, unattended' => [
        'automatic_updates.cron_updater',
        '9.8.2',
        [],
      ],
      '1 patch behind, attended' => [
        'automatic_updates.updater',
        '9.8.0',
        ['Drupal cannot be automatically updated during cron from its current version, 9.8.1, to the recommended version, 9.8.0, because Automatic Updates only supports 1 patch version update during cron.'],
      ],
      '1 patch behind, unattended' => [
        'automatic_updates.cron_updater',
        '9.8.0',
        ['Drupal cannot be automatically updated during cron from its current version, 9.8.1, to the recommended version, 9.8.0, because Automatic Updates only supports 1 patch version update during cron.'],
      ],
      'into next minor, attended' => [
        'automatic_updates.updater',
        '9.9.0',
        ['Drupal cannot be automatically updated during cron from its current version, 9.8.1, to the recommended version, 9.9.0, because Automatic Updates only supports 1 patch version update during cron.'],
      ],
      'into next minor, unattended' => [
        'automatic_updates.cron_updater',
        '9.9.0',
        ['Drupal cannot be automatically updated during cron from its current version, 9.8.1, to the recommended version, 9.9.0, because Automatic Updates only supports 1 patch version update during cron.'],
      ],
      'into previous minor, attended' => [
        'automatic_updates.updater',
        '9.7.9',
        ['Drupal cannot be automatically updated during cron from its current version, 9.8.1, to the recommended version, 9.7.9, because Automatic Updates only supports 1 patch version update during cron.'],
      ],
      'into previous minor, unattended' => [
        'automatic_updates.cron_updater',
        '9.7.9',
        ['Drupal cannot be automatically updated during cron from its current version, 9.8.1, to the recommended version, 9.7.9, because Automatic Updates only supports 1 patch version update during cron.'],
      ],
      'into next major, attended' => [
        'automatic_updates.updater',
        '10.0.0',
        ['Drupal cannot be automatically updated during cron from its current version, 9.8.1, to the recommended version, 10.0.0, because Automatic Updates only supports 1 patch version update during cron.'],
      ],
      'into next major, unattended' => [
        'automatic_updates.cron_updater',
        '10.0.0',
        ['Drupal cannot be automatically updated during cron from its current version, 9.8.1, to the recommended version, 10.0.0, because Automatic Updates only supports 1 patch version update during cron.'],
      ],
      'into previous major, attended' => [
        'automatic_updates.updater',
        '8.9.9',
        ['Drupal cannot be automatically updated during cron from its current version, 9.8.1, to the recommended version, 8.9.9, because Automatic Updates only supports 1 patch version update during cron.'],
      ],
      'into previous major, unattended' => [
        'automatic_updates.cron_updater',
        '8.9.9',
        ['Drupal cannot be automatically updated during cron from its current version, 9.8.1, to the recommended version, 8.9.9, because Automatic Updates only supports 1 patch version update during cron.'],
      ],
    ];
  }

  /**
   * Tests that the target version's patch level is validated.
   *
   * @param string $updater
   *   The updater service to use.
   * @param string $target_version
   *   The target version of Drupal core.
   * @param string[] $expected_errors
   *   The expected error messages, if any.
   *
   * @dataProvider providerTargetVersionPatchLevel
   */
  public function testTargetVersionPatchLevel(string $updater, string $target_version, array $expected_errors): void {
    $updater = $this->container->get($updater);
    $rule = new TargetVersionPatchLevel();
    $actual_errors = array_map('strval', $rule->validate($updater, '9.8.1', $target_version));
    $this->assertSame($expected_errors, $actual_errors);
  }

}
