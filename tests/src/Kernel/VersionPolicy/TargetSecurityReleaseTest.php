<?php

namespace Drupal\Tests\automatic_updates\Kernel\VersionPolicy;

use Drupal\automatic_updates\Validator\VersionPolicy\TargetSecurityRelease;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;

/**
 * @covers \Drupal\automatic_updates\Validator\VersionPolicy\TargetSecurityRelease
 *
 * @group automatic_updates
 */
class TargetSecurityReleaseTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates'];

  /**
   * Data provider for ::testTargetSecurityRelease().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerTargetSecurityRelease(): array {
    $fixture_dir = __DIR__ . '/../../../fixtures/release-history';

    return [
      'target security release, attended' => [
        'automatic_updates.updater',
        ['drupal' => $fixture_dir . '/drupal.9.8.1-security.xml'],
        [],
      ],
      'target security release, unattended' => [
        'automatic_updates.cron_updater',
        ['drupal' => $fixture_dir . '/drupal.9.8.1-security.xml'],
        [],
      ],
      'target non-security release, attended' => [
        'automatic_updates.updater',
        ['drupal' => $fixture_dir . '/drupal.9.8.2.xml'],
        ['Drupal cannot be automatically updated during cron from its current version, 9.8.0, to the recommended version, 9.8.1, because 9.8.1 is not a security release.'],
      ],
      'target non-security release, unattended' => [
        'automatic_updates.cron_updater',
        ['drupal' => $fixture_dir . '/drupal.9.8.2.xml'],
        ['Drupal cannot be automatically updated during cron from its current version, 9.8.0, to the recommended version, 9.8.1, because 9.8.1 is not a security release.'],
      ],
    ];
  }

  /**
   * Tests that the target version must be a security release.
   *
   * @param string $updater
   *   The updater service to use.
   * @param array $release_metadata
   *   The paths of the XML release metadata files to use, keyed by project
   *   name.
   * @param string[] $expected_errors
   *   The expected error messages, if any.
   *
   * @dataProvider providerTargetSecurityRelease
   *
   * @see parent::setReleaseMetadata()
   */
  public function testTargetSecurityRelease(string $updater, array $release_metadata, array $expected_errors): void {
    $this->setCoreVersion('9.8.0');
    $this->setReleaseMetadata($release_metadata);

    $updater = $this->container->get($updater);
    $rule = new TargetSecurityRelease();
    $actual_errors = array_map('strval', $rule->validate($updater, '9.8.0', '9.8.1'));
    $this->assertSame($expected_errors, $actual_errors);
  }

}
