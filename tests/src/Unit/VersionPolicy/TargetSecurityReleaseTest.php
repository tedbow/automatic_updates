<?php

namespace Drupal\Tests\automatic_updates\Unit\VersionPolicy;

use Drupal\automatic_updates\Validator\VersionPolicy\TargetSecurityRelease;
use Drupal\automatic_updates_9_3_shim\ProjectRelease;
use Drupal\Tests\automatic_updates\Traits\VersionPolicyTestTrait;
use Drupal\Tests\UnitTestCase;

/**
 * @covers \Drupal\automatic_updates\Validator\VersionPolicy\TargetSecurityRelease
 *
 * @group automatic_updates
 */
class TargetSecurityReleaseTest extends UnitTestCase {

  use VersionPolicyTestTrait;

  /**
   * Data provider for ::testTargetSecurityRelease().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerTargetSecurityRelease(): array {
    return [
      'target security release' => [
        [
          '9.8.1' => ProjectRelease::createFromArray([
            'status' => 'published',
            'release_link' => 'http://example.com/drupal-9-8-1-release',
            'version' => '9.8.1',
            'terms' => [
              'Release type' => ['Security update'],
            ],
          ]),
        ],
        [],
      ],
      'target non-security release' => [
        [
          '9.8.1' => ProjectRelease::createFromArray([
            'status' => 'published',
            'release_link' => 'http://example.com/drupal-9-8-1-release',
            'version' => '9.8.1',
          ]),
        ],
        ['Drupal cannot be automatically updated during cron from 9.8.0 to 9.8.1 because 9.8.1 is not a security release.'],
      ],
    ];
  }

  /**
   * Tests that the target version must be a security release.
   *
   * @param \Drupal\automatic_updates_9_3_shim\ProjectRelease[] $available_releases
   *   The available releases of Drupal core, keyed by version.
   * @param string[] $expected_errors
   *   The expected error messages, if any.
   *
   * @dataProvider providerTargetSecurityRelease
   */
  public function testTargetSecurityRelease(array $available_releases, array $expected_errors): void {
    $rule = new TargetSecurityRelease();
    $this->assertPolicyErrors($rule, '9.8.0', '9.8.1', $expected_errors, $available_releases);
  }

}
