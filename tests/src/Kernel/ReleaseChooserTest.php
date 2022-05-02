<?php

namespace Drupal\Tests\automatic_updates\Kernel;

use Drupal\automatic_updates_9_3_shim\ProjectRelease;

/**
 * @coversDefaultClass \Drupal\automatic_updates\ReleaseChooser
 *
 * @group automatic_updates
 */
class ReleaseChooserTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setReleaseMetadata(['drupal' => __DIR__ . '/../../fixtures/release-history/drupal.9.8.2-older-sec-release.xml']);

  }

  /**
   * Data provider for testReleases().
   *
   * @return array[]
   *   The test cases.
   */
  public function providerReleases(): array {
    return [
      'installed 9.8.0, no minor support' => [
        'chooser' => 'automatic_updates.release_chooser',
        'minor_support' => FALSE,
        'installed_version' => '9.8.0',
        'current_minor' => '9.8.2',
        'next_minor' => NULL,
      ],
      'installed 9.8.0, minor support' => [
        'chooser' => 'automatic_updates.release_chooser',
        'minor_support' => TRUE,
        'installed_version' => '9.8.0',
        'current_minor' => '9.8.2',
        'next_minor' => NULL,
      ],
      'installed 9.7.0, no minor support' => [
        'chooser' => 'automatic_updates.release_chooser',
        'minor_support' => FALSE,
        'installed_version' => '9.7.0',
        'current_minor' => '9.7.1',
        'next_minor' => NULL,
      ],
      'installed 9.7.0, minor support' => [
        'chooser' => 'automatic_updates.release_chooser',
        'minor_support' => TRUE,
        'installed_version' => '9.7.0',
        'current_minor' => '9.7.1',
        'next_minor' => '9.8.2',
      ],
      'installed 9.7.2, no minor support' => [
        'chooser' => 'automatic_updates.release_chooser',
        'minor_support' => FALSE,
        'installed_version' => '9.7.2',
        'current_minor' => NULL,
        'next_minor' => NULL,
      ],
      'installed 9.7.2, minor support' => [
        'chooser' => 'automatic_updates.release_chooser',
        'minor_support' => TRUE,
        'installed_version' => '9.7.2',
        'current_minor' => NULL,
        'next_minor' => '9.8.2',
      ],
      'cron, installed 9.8.0, no minor support' => [
        'chooser' => 'automatic_updates.cron_release_chooser',
        'minor_support' => FALSE,
        'installed_version' => '9.8.0',
        'current_minor' => '9.8.1',
        'next_minor' => NULL,
      ],
      'cron, installed 9.8.0, minor support' => [
        'chooser' => 'automatic_updates.cron_release_chooser',
        'minor_support' => TRUE,
        'installed_version' => '9.8.0',
        'current_minor' => '9.8.1',
        'next_minor' => NULL,
      ],
      'cron, installed 9.7.0, no minor support' => [
        'chooser' => 'automatic_updates.cron_release_chooser',
        'minor_support' => FALSE,
        'installed_version' => '9.7.0',
        'current_minor' => '9.7.1',
        'next_minor' => NULL,
      ],
      'cron, installed 9.7.0, minor support' => [
        'chooser' => 'automatic_updates.cron_release_chooser',
        'minor_support' => TRUE,
        'installed_version' => '9.7.0',
        'current_minor' => '9.7.1',
        'next_minor' => NULL,
      ],
      'cron, installed 9.7.2, no minor support' => [
        'chooser' => 'automatic_updates.cron_release_chooser',
        'minor_support' => FALSE,
        'installed_version' => '9.7.2',
        'current_minor' => NULL,
        'next_minor' => NULL,
      ],
      'cron, installed 9.7.2, minor support' => [
        'chooser' => 'automatic_updates.cron_release_chooser',
        'minor_support' => TRUE,
        'installed_version' => '9.7.2',
        'current_minor' => NULL,
        'next_minor' => NULL,
      ],
    ];
  }

  /**
   * Tests fetching the recommended release when an update is available.
   *
   * @param string $chooser_service
   *   The ID of release chooser service to use.
   * @param bool $minor_support
   *   Whether updates to the next minor will be allowed.
   * @param string $installed_version
   *   The installed version of Drupal core.
   * @param string|null $current_minor
   *   The expected release in the currently installed minor or NULL if none is
   *   available.
   * @param string|null $next_minor
   *   The expected release in the next minor or NULL if none is available.
   *
   * @dataProvider providerReleases
   *
   * @covers ::getLatestInInstalledMinor
   * @covers ::getLatestInNextMinor
   */
  public function testReleases(string $chooser_service, bool $minor_support, string $installed_version, ?string $current_minor, ?string $next_minor): void {
    $this->setCoreVersion($installed_version);
    $this->config('automatic_updates.settings')->set('allow_core_minor_updates', $minor_support)->save();
    /** @var \Drupal\automatic_updates\ReleaseChooser $chooser */
    $chooser = $this->container->get($chooser_service);
    $this->assertReleaseVersion($current_minor, $chooser->getLatestInInstalledMinor());
    $this->assertReleaseVersion($next_minor, $chooser->getLatestInNextMinor());
  }

  /**
   * Asserts that a project release matches a version number.
   *
   * @param string|null $version
   *   The version to check, or NULL if no version expected.
   * @param \Drupal\automatic_updates_9_3_shim\ProjectRelease|null $release
   *   The release to check, or NULL if no release is expected.
   */
  private function assertReleaseVersion(?string $version, ?ProjectRelease $release) {
    if (is_null($version)) {
      $this->assertNull($release);
    }
    else {
      $this->assertNotEmpty($release);
      $this->assertSame($version, $release->getVersion());
    }
  }

}
