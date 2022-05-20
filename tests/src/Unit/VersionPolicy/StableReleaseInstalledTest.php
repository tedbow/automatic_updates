<?php

namespace Drupal\Tests\automatic_updates\Unit\VersionPolicy;

use Drupal\automatic_updates\Validator\VersionPolicy\StableReleaseInstalled;
use Drupal\Tests\automatic_updates\Traits\VersionPolicyTestTrait;
use Drupal\Tests\UnitTestCase;

/**
 * @covers \Drupal\automatic_updates\Validator\VersionPolicy\StableReleaseInstalled
 *
 * @group automatic_updates
 */
class StableReleaseInstalledTest extends UnitTestCase {

  use VersionPolicyTestTrait;

  /**
   * Data provider for ::testStableReleaseInstalled().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerStableReleaseInstalled(): array {
    return [
      'stable version installed' => [
        '9.8.0',
        [],
      ],
      'alpha version installed' => [
        '9.8.0-alpha3',
        ['Drupal cannot be automatically updated during cron from its current version, 9.8.0-alpha3, because Automatic Updates only supports updating from stable versions during cron.'],
      ],
      'beta version installed' => [
        '9.8.0-beta7',
        ['Drupal cannot be automatically updated during cron from its current version, 9.8.0-beta7, because Automatic Updates only supports updating from stable versions during cron.'],
      ],
      'release candidate installed' => [
        '9.8.0-rc2',
        ['Drupal cannot be automatically updated during cron from its current version, 9.8.0-rc2, because Automatic Updates only supports updating from stable versions during cron.'],
      ],
    ];
  }

  /**
   * Tests that trying to update from a non-stable release raises an error.
   *
   * @param string $installed_version
   *   The installed version of Drupal core.
   * @param string[] $expected_errors
   *   The expected error messages, if any.
   *
   * @dataProvider providerStableReleaseInstalled
   */
  public function testStableReleaseInstalled(string $installed_version, array $expected_errors): void {
    $rule = new StableReleaseInstalled();
    $this->assertPolicyErrors($rule, $installed_version, '9.8.1', $expected_errors);
  }

}