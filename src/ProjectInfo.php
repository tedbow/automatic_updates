<?php

namespace Drupal\automatic_updates;

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
   * @return array|null
   *   The retrieved project information.
   *
   * @throws \RuntimeException
   *   If data about available updates cannot be retrieved.
   */
  public function getProjectInfo(): ?array {
    $available_updates = update_get_available(TRUE);
    $project_data = update_calculate_project_data($available_updates);
    return $project_data[$this->name] ?? NULL;
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
    $available_updates = update_get_available()[$this->name];
    if ($available_updates['project_status'] !== 'published') {
      throw new \RuntimeException("The project '{$this->name}' can not be updated because its status is " . $available_updates['project_status']);
    }

    // If we're already up-to-date, there's nothing else we need to do.
    if ($project['status'] === UpdateManagerInterface::CURRENT) {
      return [];
    }

    if (empty($available_updates['releases'])) {
      // If project is not current we should always have at least one release.
      throw new \RuntimeException('There was a problem getting update information. Try again later.');
    }
    $installed_version = $this->getInstalledVersion();
    $support_branches = explode(',', $available_updates['supported_branches']);
    $installable_releases = [];
    foreach ($available_updates['releases'] as $release_info) {
      $release = ProjectRelease::createFromArray($release_info);
      $version = $release->getVersion();
      $semantic_version = LegacyVersionUtility::convertToSemanticVersion($version);
      $semantic_installed_version = LegacyVersionUtility::convertToSemanticVersion($installed_version);
      if (Comparator::lessThanOrEqualTo($semantic_version, $semantic_installed_version)) {
        // Stop searching for releases as soon as we find the installed version.
        break;
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
      if (empty($project_data['existing_version'])) {
        throw new \UnexpectedValueException("Project '{$this->name}' does not have 'existing_version' set");
      }
      return $project_data['existing_version'];
    }
    return NULL;
  }

}
