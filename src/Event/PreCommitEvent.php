<?php

namespace Drupal\automatic_updates\Event;

/**
 * Event fired before staged changes are copied into the active site.
 */
class PreCommitEvent extends UpdateEvent {

  use ExcludedPathsTrait;

}
