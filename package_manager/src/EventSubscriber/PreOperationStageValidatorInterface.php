<?php

namespace Drupal\package_manager\EventSubscriber;

use Drupal\package_manager\Event\PreOperationStageEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Defines an interface for classes that validate a stage before an operation.
 */
interface PreOperationStageValidatorInterface extends EventSubscriberInterface {

  /**
   * Validates a stage before an operation.
   *
   * @param \Drupal\package_manager\Event\PreOperationStageEvent $event
   *   The stage event.
   */
  public function validateStagePreOperation(PreOperationStageEvent $event): void;

}
