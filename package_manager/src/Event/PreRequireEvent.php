<?php

namespace Drupal\package_manager\Event;

/**
 * Event fired before packages are updated to the staging area.
 */
class PreRequireEvent extends PreOperationStageEvent {

  use RequireEventTrait;

}
