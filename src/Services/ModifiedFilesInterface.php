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
   * @param bool $exception_on_failure
   *   (optional) Throw exception on HTTP failures, defaults to FALSE.
   *
   * @return \Iterator
   *   The modified files.
   */
  public function getModifiedFiles(array $extensions = [], $exception_on_failure = FALSE);

}
