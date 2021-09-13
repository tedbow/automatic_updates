<?php

namespace Drupal\automatic_updates;

use Drupal\automatic_updates_9_3_shim\ProjectRelease;
use Drupal\update\UpdateManagerInterface;

/**
 * Determines the recommended release of Drupal core to update to.
 */
class UpdateRecommender {

  /**
   * Returns up-to-date project information for Drupal core.
   *
   * @param bool $refresh
   *   (optional) Whether to fetch the latest information about available
   *   updates from drupal.org. This can be an expensive operation, so defaults
   *   to FALSE.
   *
   * @return array
   *   The retrieved project information for Drupal core.
   *
   * @throws \RuntimeException
   *   If data about available updates cannot be retrieved.
   */
  public function getProjectInfo(bool $refresh = FALSE): array {
    $available_updates = update_get_available($refresh);
    if (empty($available_updates)) {
      throw new \RuntimeException('There was a problem getting update information. Try again later.');
    }

    $project_data = update_calculate_project_data($available_updates);
    return $project_data['drupal'];
  }

  /**
   * Returns the recommended release of Drupal core.
   *
   * @param bool $refresh
   *   (optional) Whether to fetch the latest information about available
   *   updates from drupal.org. This can be an expensive operation, so defaults
   *   to FALSE.
   *
   * @return \Drupal\automatic_updates_9_3_shim\ProjectRelease|null
   *   A value object with information about the recommended release, or NULL
   *   if Drupal core is already up-to-date.
   *
   * @throws \LogicException
   *   If Drupal core is out of date and the recommended version of cannot be
   *   determined.
   */
  public function getRecommendedRelease(bool $refresh = FALSE): ?ProjectRelease {
    $project = $this->getProjectInfo($refresh);

    // If we're already up-to-date, there's nothing else we need to do.
    if ($project['status'] === UpdateManagerInterface::CURRENT) {
      return NULL;
    }
    // If we don't know what to recommend they update to, time to freak out.
    elseif (empty($project['recommended'])) {
      throw new \LogicException('Drupal core is out of date, but the recommended version could not be determined.');
    }

    $recommended_version = $project['recommended'];
    return ProjectRelease::createFromArray($project['releases'][$recommended_version]);
  }

}
