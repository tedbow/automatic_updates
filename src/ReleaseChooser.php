<?php

namespace Drupal\automatic_updates;

use Composer\Semver\Semver;
use Drupal\automatic_updates\Validator\UpdateVersionValidator;
use Drupal\automatic_updates_9_3_shim\ProjectRelease;
use Drupal\Core\Extension\ExtensionVersion;

/**
 * Defines a class to choose a release of Drupal core to update to.
 */
class ReleaseChooser {

  use VersionParsingTrait;

  /**
   * The version validator service.
   *
   * @var \Drupal\automatic_updates\Validator\UpdateVersionValidator
   */
  protected $versionValidator;

  /**
   * The project information fetcher.
   *
   * @var \Drupal\automatic_updates\ProjectInfo
   */
  protected $projectInfo;

  /**
   * Constructs an ReleaseChooser object.
   *
   * @param \Drupal\automatic_updates\Validator\UpdateVersionValidator $version_validator
   *   The version validator.
   */
  public function __construct(UpdateVersionValidator $version_validator) {
    $this->versionValidator = $version_validator;
    $this->projectInfo = new ProjectInfo();
  }

  /**
   * Refreshes the project information through the Update module.
   *
   * @return $this
   *   The called object.
   */
  public function refresh(): self {
    $this->projectInfo->getProjectInfo(TRUE);
    return $this;
  }

  /**
   * Returns the releases that are installable.
   *
   * @return \Drupal\automatic_updates_9_3_shim\ProjectRelease[]
   *   The releases that are installable according to the version validator
   *   service.
   */
  protected function getInstallableReleases(): array {
    return array_filter(
      $this->projectInfo->getInstallableReleases(),
      [$this->versionValidator, 'isValidVersion'],
      ARRAY_FILTER_USE_KEY
    );
  }

  /**
   * Gets the most recent release in the same minor as a specified version.
   *
   * @param string $version
   *   The full semantic version number, which must include a patch version.
   *
   * @return \Drupal\automatic_updates_9_3_shim\ProjectRelease|null
   *   The most recent release in the minor if available, otherwise NULL.
   *
   * @throws \InvalidArgumentException
   *   If the given semantic version number does not contain a patch version.
   */
  protected function getMostRecentReleaseInMinor(string $version): ?ProjectRelease {
    if (static::getPatchVersion($version) === NULL) {
      throw new \InvalidArgumentException("The version number $version does not contain a patch version");
    }
    $releases = $this->getInstallableReleases();
    foreach ($releases as $release) {
      if (Semver::satisfies($release->getVersion(), "~$version")) {
        return $release;
      }
    }
    return NULL;
  }

  /**
   * Gets the installed version of Drupal core.
   *
   * @return string
   *   The installed version of Drupal core.
   */
  protected function getInstalledVersion(): string {
    return $this->projectInfo->getInstalledVersion();
  }

  /**
   * Gets the latest release in the currently installed minor.
   *
   * This will only return a release if it passes the ::isValidVersion() method
   * of the version validator service injected into this class.
   *
   * @return \Drupal\automatic_updates_9_3_shim\ProjectRelease|null
   *   The latest release in the currently installed minor, if any, otherwise
   *   NULL.
   */
  public function getLatestInInstalledMinor(): ?ProjectRelease {
    return $this->getMostRecentReleaseInMinor($this->getInstalledVersion());
  }

  /**
   * Gets the latest release in the next minor.
   *
   * This will only return a release if it passes the ::isValidVersion() method
   * of the version validator service injected into this class.
   *
   * @return \Drupal\automatic_updates_9_3_shim\ProjectRelease|null
   *   The latest release in the next minor, if any, otherwise NULL.
   */
  public function getLatestInNextMinor(): ?ProjectRelease {
    $installed_version = ExtensionVersion::createFromVersionString($this->getInstalledVersion());
    $next_minor = $installed_version->getMajorVersion() . '.' . (((int) $installed_version->getMinorVersion()) + 1) . '.0';
    return $this->getMostRecentReleaseInMinor($next_minor);
  }

}
