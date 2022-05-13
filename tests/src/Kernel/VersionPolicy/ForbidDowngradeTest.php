<?php

namespace Drupal\Tests\automatic_updates\Kernel\VersionPolicy;

use Drupal\automatic_updates\Validator\VersionPolicy\ForbidDowngrade;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;

/**
 * @covers \Drupal\automatic_updates\Validator\VersionPolicy\ForbidDowngrade
 *
 * @group automatic_updates
 */
class ForbidDowngradeTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates'];

  /**
   * Data provider for ::testDowngradeForbidden().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerDowngradeForbidden(): array {
    return [
      'unknown target version, attended' => [
        'automatic_updates.updater',
        '9.8.0',
        NULL,
        ['Update version  is lower than 9.8.0, downgrading is not supported.'],
      ],
      'unknown target version, unattended' => [
        'automatic_updates.cron_updater',
        '9.8.0',
        NULL,
        ['Update version  is lower than 9.8.0, downgrading is not supported.'],
      ],
      'same versions, attended' => [
        'automatic_updates.updater',
        '9.8.0',
        '9.8.0',
        [],
      ],
      'same versions, unattended' => [
        'automatic_updates.cron_updater',
        '9.8.0',
        '9.8.0',
        [],
      ],
      'target version newer, attended' => [
        'automatic_updates.updater',
        '9.8.0',
        '9.8.2',
        [],
      ],
      'target version newer, unattended' => [
        'automatic_updates.cron_updater',
        '9.8.0',
        '9.8.2',
        [],
      ],
      'target version older, attended' => [
        'automatic_updates.updater',
        '9.8.2',
        '9.8.0',
        ['Update version 9.8.0 is lower than 9.8.2, downgrading is not supported.'],
      ],
      'target version older, unattended' => [
        'automatic_updates.cron_updater',
        '9.8.2',
        '9.8.0',
        ['Update version 9.8.0 is lower than 9.8.2, downgrading is not supported.'],
      ],
    ];
  }

  /**
   * Tests that downgrading always raises an error.
   *
   * @param string $updater
   *   The updater service to use.
   * @param string $installed_version
   *   The installed version of Drupal core.
   * @param string|null $target_version
   *   The target version of Drupal core, or NULL if not known.
   * @param string[] $expected_errors
   *   The expected error messages, if any.
   *
   * @dataProvider providerDowngradeForbidden
   */
  public function testDowngradeForbidden(string $updater, string $installed_version, ?string $target_version, array $expected_errors): void {
    $updater = $this->container->get($updater);
    $rule = new ForbidDowngrade();
    $actual_errors = array_map('strval', $rule->validate($updater, $installed_version, $target_version));
    $this->assertSame($expected_errors, $actual_errors);
  }

}
