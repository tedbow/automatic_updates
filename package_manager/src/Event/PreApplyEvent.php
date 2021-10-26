<?php

namespace Drupal\package_manager\Event;

/**
 * Event fired before staged changes are synced to the active directory.
 */
class PreApplyEvent extends StageEvent {

  use ExcludedPathsTrait;

}
