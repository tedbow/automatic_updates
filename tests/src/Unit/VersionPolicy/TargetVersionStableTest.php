<?php

namespace Drupal\Tests\automatic_updates\Unit\VersionPolicy;

use Drupal\automatic_updates\Validator\VersionPolicy\TargetVersionStable;
use Drupal\Tests\automatic_updates\Traits\VersionPolicyTestTrait;
use Drupal\Tests\UnitTestCase;

/**
 * @covers \Drupal\automatic_updates\Validator\VersionPolicy\TargetVersionStable
 *
 * @group automatic_updates
 */
class TargetVersionStableTest extends UnitTestCase {

  use VersionPolicyTestTrait;

  /**
   * Data provider for ::testTargetVersionStable().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerTargetVersionStable(): array {
    return [
      'stable target version' => [
        '9.9.0',
        [],
      ],
      'dev target version' => [
        '9.9.0-dev',
        ['Drupal cannot be automatically updated during cron to the recommended version, 9.9.0-dev, because Automatic Updates only supports updating to stable versions during cron.'],
      ],
      'alpha target version' => [
        '9.9.0-alpha3',
        ['Drupal cannot be automatically updated during cron to the recommended version, 9.9.0-alpha3, because Automatic Updates only supports updating to stable versions during cron.'],
      ],
      'beta target version' => [
        '9.9.0-beta7',
        ['Drupal cannot be automatically updated during cron to the recommended version, 9.9.0-beta7, because Automatic Updates only supports updating to stable versions during cron.'],
      ],
      'release candidate target version' => [
        '9.9.0-rc2',
        ['Drupal cannot be automatically updated during cron to the recommended version, 9.9.0-rc2, because Automatic Updates only supports updating to stable versions during cron.'],
      ],
    ];
  }

  /**
   * Tests that trying to update to a non-stable version raises an error.
   *
   * @param string $target_version
   *   The target version of Drupal core.
   * @param string[] $expected_errors
   *   The expected error messages, if any.
   *
   * @dataProvider providerTargetVersionStable
   */
  public function testTargetVersionStable(string $target_version, array $expected_errors): void {
    $rule = new TargetVersionStable();
    $this->assertPolicyErrors($rule, '9.8.0', $target_version, $expected_errors);
  }

}
