<?php

namespace Drupal\package_manager\EventSubscriber;

use Drupal\package_manager\Event\PostOperationStageEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Defines an interface for classes that validate a stage after an operation.
 */
interface PostOperationStageValidatorInterface extends EventSubscriberInterface {

  /**
   * Validates a stage after an operation.
   *
   * @param \Drupal\package_manager\Event\PostOperationStageEvent $event
   *   The stage event.
   */
  public function validateStagePostOperation(PostOperationStageEvent $event): void;

}
