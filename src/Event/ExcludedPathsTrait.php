<?php

namespace Drupal\automatic_updates\Event;

/**
 * Common functionality for events which can collect excluded paths.
 */
trait ExcludedPathsTrait {

  /**
   * Paths to exclude from the update.
   *
   * @var string[]
   */
  protected $excludedPaths = [];

  /**
   * Adds an absolute path to exclude from the update operation.
   *
   * @todo This should only accept paths relative to the active directory.
   *
   * @param string $path
   *   The path to exclude.
   */
  public function excludePath(string $path): void {
    $this->excludedPaths[] = $path;
  }

  /**
   * Returns the paths to exclude from the update operation.
   *
   * @return string[]
   *   The paths to exclude.
   */
  public function getExcludedPaths(): array {
    return array_unique($this->excludedPaths);
  }

}
