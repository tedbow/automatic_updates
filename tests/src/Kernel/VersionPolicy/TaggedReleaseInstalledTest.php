<?php

namespace Drupal\Tests\automatic_updates\Kernel\VersionPolicy;

use Drupal\automatic_updates\Validator\VersionPolicy\TaggedReleaseInstalled;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;

/**
 * @covers \Drupal\automatic_updates\Validator\VersionPolicy\TaggedReleaseInstalled
 *
 * @group automatic_updates
 */
class TaggedReleaseInstalledTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates'];

  /**
   * Data provider for ::testTaggedReleaseInstalled().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerTaggedReleaseInstalled(): array {
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
        [],
      ],
      'alpha version installed, unattended' => [
        'automatic_updates.cron_updater',
        '9.8.0-alpha3',
        [],
      ],
      'beta version installed, attended' => [
        'automatic_updates.updater',
        '9.8.0-beta7',
        [],
      ],
      'beta version installed, unattended' => [
        'automatic_updates.cron_updater',
        '9.8.0-beta7',
        [],
      ],
      'release candidate installed, attended' => [
        'automatic_updates.updater',
        '9.8.0-rc2',
        [],
      ],
      'release candidate installed, unattended' => [
        'automatic_updates.cron_updater',
        '9.8.0-rc2',
        [],
      ],
      'dev snapshot installed, attended' => [
        'automatic_updates.updater',
        '9.8.0-dev',
        ['Drupal cannot be automatically updated from the installed version, 9.8.0-dev, because automatic updates from a dev version to any other version are not supported.'],
      ],
      'dev snapshot installed, unattended' => [
        'automatic_updates.cron_updater',
        '9.8.0-dev',
        ['Drupal cannot be automatically updated from the installed version, 9.8.0-dev, because automatic updates from a dev version to any other version are not supported.'],
      ],
    ];
  }

  /**
   * Tests that trying to update from a dev snapshot raises an error.
   *
   * @param string $updater
   *   The updater service to use.
   * @param string $installed_version
   *   The installed version of Drupal core.
   * @param string[] $expected_errors
   *   The expected error messages, if any.
   *
   * @dataProvider providerTaggedReleaseInstalled
   */
  public function testTaggedReleaseInstalled(string $updater, string $installed_version, array $expected_errors): void {
    $updater = $this->container->get($updater);
    $rule = new TaggedReleaseInstalled();
    $actual_errors = array_map('strval', $rule->validate($updater, $installed_version, '9.8.1'));
    $this->assertSame($expected_errors, $actual_errors);
  }

}
