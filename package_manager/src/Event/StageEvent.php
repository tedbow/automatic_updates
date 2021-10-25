<?php

namespace Drupal\package_manager\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Base class for all events related to the life cycle of the staging area.
 */
abstract class StageEvent extends Event {
}
