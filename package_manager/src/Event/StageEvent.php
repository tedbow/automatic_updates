<?php

declare(strict_types = 1);

namespace Drupal\package_manager\Event;

use Drupal\package_manager\Stage;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Base class for all events related to the life cycle of the stage.
 */
abstract class StageEvent extends Event {

  /**
   * Constructs a StageEvent object.
   *
   * @param \Drupal\package_manager\Stage $stage
   *   The stage which fired this event.
   */
  public function __construct(public readonly Stage $stage) {
  }

}
