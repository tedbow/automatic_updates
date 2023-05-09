<?php

declare(strict_types = 1);

namespace Drupal\package_manager\Event;

/**
 * Event fired after the stage directory is destroyed.
 *
 * If the stage is being force destroyed, $this->stage may be an object of a
 * different class than the one that originally created the stage directory.
 *
 * @see \Drupal\package_manager\StageBase::destroy()
 */
final class PostDestroyEvent extends StageEvent {
}
