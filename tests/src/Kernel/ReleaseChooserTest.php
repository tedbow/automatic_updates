<?php

namespace Drupal\Tests\automatic_updates\Kernel;

use Drupal\Core\Extension\ExtensionVersion;
use Drupal\update\ProjectRelease;

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
    $this->setReleaseMetadata([
      'drupal' => __DIR__ . '/../../../package_manager/tests/fixtures/release-history/drupal.9.8.2-older-sec-release.xml',
    ]);
  }

  /**
   * Data provider for testReleases().
   *
   * @return mixed[][]
   *   The test cases.
   */
  public function providerReleases(): array {
    return [
      'installed 9.8.0, no minor support' => [
        'updater' => 'automatic_updates.updater',
        'minor_support' => FALSE,
        'installed_version' => '9.8.0',
        'current_minor' => '9.8.2',
        'next_minor' => NULL,
      ],
      'installed 9.8.0, minor support' => [
        'updater' => 'automatic_updates.updater',
        'minor_support' => TRUE,
        'installed_version' => '9.8.0',
        'current_minor' => '9.8.2',
        'next_minor' => NULL,
      ],
      'installed 9.7.0, no minor support' => [
        'updater' => 'automatic_updates.updater',
        'minor_support' => FALSE,
        'installed_version' => '9.7.0',
        'current_minor' => '9.7.1',
        'next_minor' => NULL,
      ],
      'installed 9.7.0, minor support' => [
        'updater' => 'automatic_updates.updater',
        'minor_support' => TRUE,
        'installed_version' => '9.7.0',
        'current_minor' => '9.7.1',
        'next_minor' => '9.8.2',
      ],
      'installed 9.7.2, no minor support' => [
        'updater' => 'automatic_updates.updater',
        'minor_support' => FALSE,
        'installed_version' => '9.7.2',
        'current_minor' => NULL,
        'next_minor' => NULL,
      ],
      'installed 9.7.2, minor support' => [
        'updater' => 'automatic_updates.updater',
        'minor_support' => TRUE,
        'installed_version' => '9.7.2',
        'current_minor' => NULL,
        'next_minor' => '9.8.2',
      ],
      'cron, installed 9.8.0, no minor support' => [
        'updater' => 'automatic_updates.cron_updater',
        'minor_support' => FALSE,
        'installed_version' => '9.8.0',
        'current_minor' => '9.8.1',
        'next_minor' => NULL,
      ],
      'cron, installed 9.8.0, minor support' => [
        'updater' => 'automatic_updates.cron_updater',
        'minor_support' => TRUE,
        'installed_version' => '9.8.0',
        'current_minor' => '9.8.1',
        'next_minor' => NULL,
      ],
      'cron, installed 9.7.0, no minor support' => [
        'updater' => 'automatic_updates.cron_updater',
        'minor_support' => FALSE,
        'installed_version' => '9.7.0',
        'current_minor' => '9.7.1',
        'next_minor' => NULL,
      ],
      'cron, installed 9.7.0, minor support' => [
        'updater' => 'automatic_updates.cron_updater',
        'minor_support' => TRUE,
        'installed_version' => '9.7.0',
        'current_minor' => '9.7.1',
        'next_minor' => NULL,
      ],
      'cron, installed 9.7.2, no minor support' => [
        'updater' => 'automatic_updates.cron_updater',
        'minor_support' => FALSE,
        'installed_version' => '9.7.2',
        'current_minor' => NULL,
        'next_minor' => NULL,
      ],
      'cron, installed 9.7.2, minor support' => [
        'updater' => 'automatic_updates.cron_updater',
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
   * @param string $updater_service
   *   The ID of the updater service to use.
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
   * @covers ::getMostRecentReleaseInMinor
   */
  public function testReleases(string $updater_service, bool $minor_support, string $installed_version, ?string $current_minor, ?string $next_minor): void {
    $this->setCoreVersion($installed_version);
    $this->config('automatic_updates.settings')->set('allow_core_minor_updates', $minor_support)->save();
    /** @var \Drupal\automatic_updates\ReleaseChooser $chooser */
    $chooser = $this->container->get('automatic_updates.release_chooser');
    /** @var \Drupal\automatic_updates\Updater $updater */
    $updater = $this->container->get($updater_service);
    $this->assertReleaseVersion($current_minor, $chooser->getLatestInInstalledMinor($updater));
    $this->assertReleaseVersion($next_minor, $chooser->getLatestInNextMinor($updater));

    $this->assertReleaseVersion($current_minor, $chooser->getMostRecentReleaseInMinor($updater, $this->getRelativeVersion($installed_version, 0)));
    $next_minor_version = $this->getRelativeVersion($installed_version, 1);
    $this->assertReleaseVersion($next_minor, $chooser->getMostRecentReleaseInMinor($updater, $next_minor_version));
    $previous_minor_version = $this->getRelativeVersion($installed_version, -1);
    // The chooser should never return a release for a minor before the
    // currently installed version.
    $this->assertReleaseVersion(NULL, $chooser->getMostRecentReleaseInMinor($updater, $previous_minor_version));
  }

  /**
   * Asserts that a project release matches a version number.
   *
   * @param string|null $version
   *   The version to check, or NULL if no version expected.
   * @param \Drupal\update\ProjectRelease|null $release
   *   The release to check, or NULL if no release is expected.
   */
  private function assertReleaseVersion(?string $version, ?ProjectRelease $release): void {
    if (is_null($version)) {
      $this->assertNull($release);
    }
    else {
      $this->assertNotEmpty($release);
      $this->assertSame($version, $release->getVersion());
    }
  }

  /**
   * Gets a version number in a minor version relative to another version.
   *
   * @param string $version
   *   The version string.
   * @param int $minor_offset
   *   The minor offset.
   *
   * @return string
   *   The first patch release in a minor relative to the version string.
   */
  private function getRelativeVersion(string $version, int $minor_offset): string {
    $installed_version_object = ExtensionVersion::createFromVersionString($version);
    return $installed_version_object->getMajorVersion() . '.' . (((int) $installed_version_object->getMinorVersion()) + $minor_offset) . '.0';
  }

}
