<?php

namespace Drupal\automatic_updates\Services;

use Drupal\automatic_updates\UpdateMetadata;

/**
 * Interface UpdateInterface.
 */
interface UpdateInterface {

  /**
   * Update a project to the next release.
   *
   * @param \Drupal\automatic_updates\UpdateMetadata $metadata
   *   The update metadata.
   *
   * @return bool
   *   TRUE if project was successfully updated, FALSE otherwise.
   */
  public function update(UpdateMetadata $metadata);

}
