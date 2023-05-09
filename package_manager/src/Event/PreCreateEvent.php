<?php

declare(strict_types = 1);

namespace Drupal\package_manager\Event;

use Drupal\package_manager\StageBase;

/**
 * Event fired before a stage directory is created.
 */
final class PreCreateEvent extends PreOperationStageEvent {

  /**
   * Paths to exclude from the update.
   *
   * @var string[]
   */
  protected array $excludedPaths = [];

  /**
   * Returns the paths to exclude from the current operation.
   *
   * @return string[]
   *   The paths to exclude.
   */
  public function getExcludedPaths(): array {
    return array_unique($this->excludedPaths);
  }

  /**
   * Constructs a PreCreateEvent object.
   *
   * @param \Drupal\package_manager\StageBase $stage
   *   The stage which fired this event.
   * @param string[] $paths_to_exclude
   *   The list of paths to exclude. These will not be copied into the stage
   *   directory when it is created.
   */
  public function __construct(StageBase $stage, array $paths_to_exclude) {
    parent::__construct($stage);
    $this->excludedPaths = $paths_to_exclude;
  }

}
