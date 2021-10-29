<?php

namespace Drupal\package_manager\EventSubscriber;

use Drupal\package_manager\Event\StageEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Defines an interface for services that validate a stage over its life cycle.
 */
interface StageValidatorInterface extends EventSubscriberInterface {

  /**
   * Validates a stage at various points during its life cycle.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   */
  public function validateStage(StageEvent $event): void;

}
