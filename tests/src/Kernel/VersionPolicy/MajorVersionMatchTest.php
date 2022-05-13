<?php

namespace Drupal\Tests\automatic_updates\Kernel\VersionPolicy;

use Drupal\automatic_updates\Validator\VersionPolicy\MajorVersionMatch;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;

/**
 * @covers \Drupal\automatic_updates\Validator\VersionPolicy\MajorVersionMatch
 *
 * @group automatic_updates
 */
class MajorVersionMatchTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates'];

  /**
   * Data provider for ::testMajorVersionMatch().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerMajorVersionMatch(): array {
    return [
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
      'target version newer in same minor, attended' => [
        'automatic_updates.updater',
        '9.8.0',
        '9.8.2',
        [],
      ],
      'target version newer in same minor, unattended' => [
        'automatic_updates.cron_updater',
        '9.8.0',
        '9.8.2',
        [],
      ],
      'target version newer in different minor, attended' => [
        'automatic_updates.updater',
        '9.8.0',
        '9.9.2',
        [],
      ],
      'target version newer in different minor, unattended' => [
        'automatic_updates.cron_updater',
        '9.8.0',
        '9.9.2',
        [],
      ],
      'target version older in same minor, attended' => [
        'automatic_updates.updater',
        '9.8.2',
        '9.8.0',
        [],
      ],
      'target version older in same minor, unattended' => [
        'automatic_updates.cron_updater',
        '9.8.2',
        '9.8.0',
        [],
      ],
      'target version older in different minor, attended' => [
        'automatic_updates.updater',
        '9.8.0',
        '9.7.2',
        [],
      ],
      'target version older in different minor, unattended' => [
        'automatic_updates.cron_updater',
        '9.8.0',
        '9.7.2',
        [],
      ],
      'target version newer in different major, attended' => [
        'automatic_updates.updater',
        '9.8.0',
        '10.0.0',
        ['Drupal cannot be automatically updated from its current version, 9.8.0, to the recommended version, 10.0.0, because automatic updates from one major version to another are not supported.'],
      ],
      'target version newer in different major, unattended' => [
        'automatic_updates.cron_updater',
        '9.8.0',
        '10.0.0',
        ['Drupal cannot be automatically updated from its current version, 9.8.0, to the recommended version, 10.0.0, because automatic updates from one major version to another are not supported.'],
      ],
      'target version older in different major, attended' => [
        'automatic_updates.updater',
        '9.8.0',
        '8.9.0',
        ['Drupal cannot be automatically updated from its current version, 9.8.0, to the recommended version, 8.9.0, because automatic updates from one major version to another are not supported.'],
      ],
      'target version older in different major, unattended' => [
        'automatic_updates.cron_updater',
        '9.8.0',
        '8.9.0',
        ['Drupal cannot be automatically updated from its current version, 9.8.0, to the recommended version, 8.9.0, because automatic updates from one major version to another are not supported.'],
      ],
    ];
  }

  /**
   * Tests that trying to update across major versions raises an error.
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
   * @dataProvider providerMajorVersionMatch
   */
  public function testMajorVersionMatch(string $updater, string $installed_version, ?string $target_version, array $expected_errors): void {
    $updater = $this->container->get($updater);
    $rule = new MajorVersionMatch();
    $actual_errors = array_map('strval', $rule->validate($updater, $installed_version, $target_version));
    $this->assertSame($expected_errors, $actual_errors);
  }

}
