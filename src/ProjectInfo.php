<?php

namespace Drupal\automatic_updates;

use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use Drupal\automatic_updates_9_3_shim\ProjectRelease;
use Drupal\update\UpdateManagerInterface;

/**
 * Defines a class for retrieving project information from Update module.
 *
 * @internal
 *   External code should use the Update API directly.
 */
class ProjectInfo {

  /**
   * The project name.
   *
   * @var string
   */
  protected $name;

  /**
   * Constructs a ProjectInfo object.
   *
   * @param string $name
   *   The project name.
   */
  public function __construct(string $name) {
    $this->name = $name;
  }

  /**
   * Returns up-to-date project information.
   *
   * @param bool $refresh
   *   (optional) Whether to fetch the latest information about available
   *   updates from drupal.org. This can be an expensive operation, so defaults
   *   to FALSE.
   *
   * @return array|null
   *   The retrieved project information.
   *
   * @throws \RuntimeException
   *   If data about available updates cannot be retrieved.
   */
  public function getProjectInfo(bool $refresh = FALSE): ?array {
    $available_updates = update_get_available($refresh);
    $project_data = update_calculate_project_data($available_updates);
    return $project_data[$this->name] ?? NULL;
  }

  /**
   * Gets all project releases to which the site can update.
   *
   * @param bool $refresh
   *   (optional) Whether to fetch the latest information about available
   *   updates from drupal.org. This can be an expensive operation, so defaults
   *   to FALSE.
   *
   * @return \Drupal\automatic_updates_9_3_shim\ProjectRelease[]|null
   *   If the project information is available, an array of releases that can be
   *   installed, keyed by version number; otherwise NULL. The releases are in
   *   descending order by version number (i.e., higher versions are listed
   *   first).
   *
   * @throws \RuntimeException
   *   Thrown if $refresh is TRUE and there are no available releases.
   *
   * @todo Remove or simplify this function in https://www.drupal.org/i/3252190.
   */
  public function getInstallableReleases(bool $refresh = FALSE): ?array {
    $project = $this->getProjectInfo($refresh);
    if (!$project) {
      return NULL;
    }
    $installed_version = $this->getInstalledVersion();
    // If we refreshed and we were able to get available releases we should
    // always have at least have the current release stored.
    if ($refresh && empty($project['releases'])) {
      throw new \RuntimeException('There was a problem getting update information. Try again later.');
    }
    // If we're already up-to-date, there's nothing else we need to do.
    if ($project['status'] === UpdateManagerInterface::CURRENT) {
      return [];
    }
    elseif (empty($project['recommended'])) {
      // If we don't know what to recommend they update to, time to freak out.
      throw new \LogicException("The '{$this->name}' project is out of date, but the recommended version could not be determined.");
    }
    $installable_releases = [];
    if (Comparator::greaterThan($project['recommended'], $installed_version)) {
      $release = ProjectRelease::createFromArray($project['releases'][$project['recommended']]);
      $installable_releases[$release->getVersion()] = $release;
    }
    if (!empty($project['security updates'])) {
      foreach ($project['security updates'] as $security_update) {
        $release = ProjectRelease::createFromArray($security_update);
        $version = $release->getVersion();
        if (Comparator::greaterThan($version, $installed_version)) {
          $installable_releases[$version] = $release;
        }
      }
    }
    $sorted_versions = Semver::rsort(array_keys($installable_releases));
    return array_replace(array_flip($sorted_versions), $installable_releases);
  }

  /**
   * Returns the installed project version, according to the Update module.
   *
   * @param bool $refresh
   *   (optional) Whether to fetch the latest information about available
   *   updates from drupal.org. This can be an expensive operation, so defaults
   *   to FALSE.
   *
   * @return string|null
   *   The installed project version as known to the Update module or NULL if
   *   the project information is not available.
   */
  public function getInstalledVersion(bool $refresh = FALSE): ?string {
    if ($project_data = $this->getProjectInfo($refresh)) {
      return $project_data['existing_version'];
    }
    return NULL;
  }

}
