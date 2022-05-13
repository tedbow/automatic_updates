<?php

namespace Drupal\Tests\automatic_updates\Kernel\VersionPolicy;

use Drupal\automatic_updates\Validator\VersionPolicy\TargetVersionInstallable;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;

/**
 * @covers \Drupal\automatic_updates\Validator\VersionPolicy\TargetVersionInstallable
 *
 * @group automatic_updates
 */
class TargetVersionInstallableTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates'];

  /**
   * Data provider for ::testTargetVersionInstallable().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerTargetVersionInstallable(): array {
    return [
      'no available releases, attended' => [
        'automatic_updates.updater',
        '9.8.2',
        '9.8.2',
        ['Cannot update Drupal core to 9.8.2 because it is not in the list of installable releases.'],
      ],
      'no available releases, unattended' => [
        'automatic_updates.cron_updater',
        '9.8.2',
        '9.8.2',
        ['Cannot update Drupal core to 9.8.2 because it is not in the list of installable releases.'],
      ],
      'invalid target, attended' => [
        'automatic_updates.updater',
        '9.8.2',
        '9.8.99',
        ['Cannot update Drupal core to 9.8.99 because it is not in the list of installable releases.'],
      ],
      'invalid target, unattended' => [
        'automatic_updates.cron_updater',
        '9.8.2',
        '9.8.99',
        ['Cannot update Drupal core to 9.8.99 because it is not in the list of installable releases.'],
      ],
      'valid target, attended' => [
        'automatic_updates.updater',
        '9.8.1',
        '9.8.2',
        [],
      ],
      'valid target, unattended' => [
        'automatic_updates.cron_updater',
        '9.8.1',
        '9.8.2',
        [],
      ],
    ];
  }

  /**
   * Tests that the target version must be a known, installable release.
   *
   * @param string $updater
   *   The updater service to use.
   * @param string $installed_version
   *   The installed version of Drupal.
   * @param string $target_version
   *   The target version of Drupal.
   * @param string[] $expected_errors
   *   The expected error messages, if any.
   *
   * @dataProvider providerTargetVersionInstallable
   */
  public function testTargetVersionInstallable(string $updater, string $installed_version, string $target_version, array $expected_errors): void {
    $this->setCoreVersion($installed_version);
    $this->setReleaseMetadata([
      'drupal' => __DIR__ . '/../../../fixtures/release-history/drupal.9.8.2.xml',
    ]);

    $updater = $this->container->get($updater);
    $rule = new TargetVersionInstallable();
    $actual_errors = array_map('strval', $rule->validate($updater, $installed_version, $target_version));
    $this->assertSame($expected_errors, $actual_errors);
  }

}
