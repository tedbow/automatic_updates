<?php

namespace Drupal\package_manager\Event;

/**
 * Event fired after packages are updated to the staging area.
 */
class PostRequireEvent extends StageEvent {

  use RequireEventTrait;

}
