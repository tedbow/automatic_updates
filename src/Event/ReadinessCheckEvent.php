<?php

namespace Drupal\automatic_updates\Event;

/**
 * Event fired when checking if the site could perform an update.
 */
class ReadinessCheckEvent extends UpdateEvent {

  use PackagesAwareTrait;

}
