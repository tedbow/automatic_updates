<?php

namespace Drupal\automatic_updates\Event;

/**
 * Event fired before an update begins.
 */
class PreStartEvent extends UpdateEvent {

  use ExcludedPathsTrait;

}
