<?php

namespace Drupal\automatic_updates\Services;

/**
 * Modified files service interface.
 */
interface ModifiedFilesInterface {

  /**
   * Get list of modified files.
   *
   * @param array $extensions
   *   The list of extensions, keyed by extension name with values an info
   *   array.
   *
   * @return array
   *   The modified files.
   */
  public function getModifiedFiles(array $extensions = []);

}
