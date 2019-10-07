<?php

namespace Drupal\automatic_updates\Services;

/**
 * Interface UpdateInterface.
 */
interface UpdateInterface {

  /**
   * Update a project to the next release.
   *
   * @param string $project_name
   *   The project name.
   * @param string $project_type
   *   The project type.
   * @param string $from_version
   *   The current project version.
   * @param string $to_version
   *   The desired next project version.
   *
   * @return bool
   *   TRUE if project was successfully updated, FALSE otherwise.
   */
  public function update($project_name, $project_type, $from_version, $to_version);

}
