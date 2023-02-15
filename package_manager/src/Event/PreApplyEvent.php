<?php

declare(strict_types = 1);

namespace Drupal\package_manager\Event;

use Drupal\package_manager\Stage;

/**
 * Event fired before staged changes are synced to the active directory.
 */
class PreApplyEvent extends PreOperationStageEvent {

  use ExcludedPathsTrait;

  /**
   * Constructs a PreApplyEvent object.
   *
   * @param \Drupal\package_manager\Stage $stage
   *   The stage which fired this event.
   * @param string[] $ignored_paths
   *   The list of ignored paths. These will not be copied from the stage
   *   directory to the active directory, nor be deleted from the active
   *   directory if they exist, when the stage directory is copied back into
   *   the active directory.
   */
  public function __construct(Stage $stage, array $ignored_paths) {
    parent::__construct($stage);
    $this->excludedPaths = $ignored_paths;
  }

}
