<?php

namespace Drupal\package_manager;

use Composer\Semver\Comparator;
use Drupal\update\ProjectRelease;
use Drupal\Core\Extension\ExtensionVersion;
use Drupal\update\UpdateManagerInterface;

/**
 * Defines a class for retrieving project information from Update module.
 *
 * @internal
 *   This is an internal part of Automatic Updates and may be changed or removed
 *   at any time without warning. External code should use the Update API
 *   directly.
 */
final class ProjectInfo {

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
   * Determines if a release can be installed.
   *
   * @param \Drupal\update\ProjectRelease $release
   *   The project release.
   * @param string[] $support_branches
   *   The supported branches.
   *
   * @return bool
   *   TRUE if the release is installable, otherwise FALSE. A release will be
   *   considered installable if it is secure, published, supported, and in
   *   a supported branch.
   */
  private function isInstallable(ProjectRelease $release, array $support_branches): bool {
    if ($release->isInsecure() || !$release->isPublished() || $release->isUnsupported()) {
      return FALSE;
    }
    $version = ExtensionVersion::createFromVersionString($release->getVersion());
    if ($version->getVersionExtra() === 'dev') {
      return FALSE;
    }
    foreach ($support_branches as $support_branch) {
      $support_branch_version = ExtensionVersion::createFromSupportBranch($support_branch);
      if ($support_branch_version->getMajorVersion() === $version->getMajorVersion() && $support_branch_version->getMinorVersion() === $version->getMinorVersion()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns up-to-date project information.
   *
   * @return mixed[]|null
   *   The retrieved project information.
   *
   * @throws \RuntimeException
   *   If data about available updates cannot be retrieved.
   */
  public function getProjectInfo(): ?array {
    $available_updates = $this->getAvailableProjects();
    $project_data = update_calculate_project_data($available_updates);
    if (!isset($project_data[$this->name])) {
      return $available_updates[$this->name] ?? NULL;
    }
    return $project_data[$this->name];
  }

  /**
   * Gets all project releases to which the site can update.
   *
   * @return \Drupal\update\ProjectRelease[]|null
   *   If the project information is available, an array of releases that can be
   *   installed, keyed by version number; otherwise NULL. The releases are in
   *   descending order by version number (i.e., higher versions are listed
   *   first). The currently installed version of the project, and any older
   *   versions, are not considered installable releases.
   *
   * @throws \RuntimeException
   *   Thrown if there are no available releases.
   *
   * @todo Remove or simplify this function in https://www.drupal.org/i/3252190.
   */
  public function getInstallableReleases(): ?array {
    $project = $this->getProjectInfo();
    if (!$project) {
      return NULL;
    }
    $available_updates = $this->getAvailableProjects()[$this->name];
    if ($available_updates['project_status'] !== 'published') {
      throw new \RuntimeException("The project '{$this->name}' can not be updated because its status is " . $available_updates['project_status']);
    }
    $installed_version = $this->getInstalledVersion();

    if ($installed_version) {
      // If the project is installed, and we're already up-to-date, there's
      // nothing else we need to do.
      if ($project['status'] === UpdateManagerInterface::CURRENT) {
        return [];
      }

      if (empty($available_updates['releases'])) {
        // If project is installed but not current we should always have at
        // least one release.
        throw new \RuntimeException('There was a problem getting update information. Try again later.');
      }
    }

    $support_branches = explode(',', $available_updates['supported_branches']);
    $installable_releases = [];
    foreach ($available_updates['releases'] as $release_info) {
      $release = ProjectRelease::createFromArray($release_info);
      $version = $release->getVersion();
      if ($installed_version) {
        $semantic_version = LegacyVersionUtility::convertToSemanticVersion($version);
        $semantic_installed_version = LegacyVersionUtility::convertToSemanticVersion($installed_version);
        if (Comparator::lessThanOrEqualTo($semantic_version, $semantic_installed_version)) {
          // If the project is installed stop searching for releases as soon as
          // we find the installed version.
          break;
        }
      }
      if ($this->isInstallable($release, $support_branches)) {
        $installable_releases[$version] = $release;
      }
    }
    return $installable_releases;
  }

  /**
   * Returns the installed project version, according to the Update module.
   *
   * @return string|null
   *   The installed project version as known to the Update module or NULL if
   *   the project information is not available.
   */
  public function getInstalledVersion(): ?string {
    if ($project_data = $this->getProjectInfo()) {
      return $project_data['existing_version'] ?? NULL;
    }
    return NULL;
  }

  /**
   * Gets the available projects.
   *
   * @return array
   *   The available projects keyed by project machine name in the format
   *   returned by update_get_available(). If the project specified in ::name is
   *   not returned from update_get_available() this project will be explicitly
   *   fetched and added the return value of this function.
   *
   * @see \update_get_available()
   */
  private function getAvailableProjects(): array {
    $available_projects = update_get_available(TRUE);
    // update_get_available() will only returns projects that are in the active
    // codebase. If the project specified by ::name is not returned in
    // $available_projects, it means it is not in the active codebase, therefore
    // we will retrieve the project information from Package Manager's own
    // update processor service.
    if (!isset($available_projects[$this->name])) {
      /** @var \Drupal\package_manager\PackageManagerUpdateProcessor $update_processor */
      $update_processor = \Drupal::service('package_manager.update_processor');
      if ($project_data = $update_processor->getProjectData($this->name)) {
        $available_projects[$this->name] = $project_data;
      }
    }
    return $available_projects;
  }

  /**
   * Checks if the installed version of this project is safe to use.
   *
   * @return bool
   *   TRUE if the installed version of this project is secure, supported, and
   *   published. Otherwise, or if the project information could not be
   *   retrieved, returns FALSE.
   */
  public function isInstalledVersionSafe(): bool {
    $project_data = $this->getProjectInfo();
    if ($project_data) {
      $unsafe = [
        UpdateManagerInterface::NOT_SECURE,
        UpdateManagerInterface::NOT_SUPPORTED,
        UpdateManagerInterface::REVOKED,
      ];
      return !in_array($project_data['status'], $unsafe, TRUE);
    }
    // If we couldn't get project data, assume the installed version is unsafe.
    return FALSE;
  }

}
