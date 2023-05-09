<?php

declare(strict_types = 1);

namespace Drupal\package_manager\Event;

use Drupal\package_manager\StageBase;

/**
 * Event fired before staged changes are synced to the active directory.
 */
final class PreApplyEvent extends PreOperationStageEvent {

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
   * Constructs a PreApplyEvent object.
   *
   * @param \Drupal\package_manager\StageBase $stage
   *   The stage which fired this event.
   * @param string[] $paths_to_exclude
   *   The list of paths to exclude. These will not be copied from the stage
   *   directory to the active directory, nor be deleted from the active
   *   directory if they exist, when the stage directory is copied back into
   *   the active directory.
   */
  public function __construct(StageBase $stage, array $paths_to_exclude) {
    parent::__construct($stage);
    $this->excludedPaths = $paths_to_exclude;
  }

}
