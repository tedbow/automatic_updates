<?php

declare(strict_types = 1);

namespace Drupal\package_manager\Event;

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
   * Returns the paths to exclude from the current operation.
   *
   * @return string[]
   *   The paths to exclude.
   */
  public function getExcludedPaths(): array {
    return array_unique($this->excludedPaths);
  }

}
