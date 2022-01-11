<?php

namespace Drupal\package_manager\Event;

/**
 * Event fired before the staging area is destroyed.
 *
 * If the stage is being force destroyed, ::getStage() may return an object of a
 * different class than the one that originally created the staging area.
 *
 * @see \Drupal\package_manager\Stage::destroy()
 */
class PreDestroyEvent extends PreOperationStageEvent {
}
