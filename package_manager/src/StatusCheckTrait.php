<?php

declare(strict_types = 1);

namespace Drupal\package_manager;

use Drupal\package_manager\Event\CollectIgnoredPathsEvent;
use Drupal\package_manager\Event\StatusCheckEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Contains helper methods to run status checks on a stage.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not use or interact with
 *   this trait.
 */
trait StatusCheckTrait {

  /**
   * Runs a status check for a stage and returns the results, if any.
   *
   * @param \Drupal\package_manager\Stage $stage
   *   The stage to run the status check for.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   (optional) The event dispatcher service.
   *
   * @return \Drupal\package_manager\ValidationResult[]
   *   The results of the status check. If a readiness check was also done,
   *   its results will be included.
   */
  protected function runStatusCheck(Stage $stage, EventDispatcherInterface $event_dispatcher = NULL): array {
    $event_dispatcher ??= \Drupal::service('event_dispatcher');
    try {
      $ignored_paths_event = new CollectIgnoredPathsEvent($stage);
      $event_dispatcher->dispatch($ignored_paths_event);
      $event = new StatusCheckEvent($stage, $ignored_paths_event->getAll());
    }
    catch (\Throwable $throwable) {
      // We can dispatch the status check event without the ignored paths, but
      // it must be set explicitly to NULL, to allow those status checks to run
      // that do not need the ignored paths.
      $event = new StatusCheckEvent($stage, NULL);
      // Add the error that was encountered so that regardless of any other
      // validation errors BaseRequirementsFulfilledValidator will stop the
      // event propagation after the base requirement validators have run.
      // @see \Drupal\package_manager\Validator\BaseRequirementsFulfilledValidator
      $event->addErrorFromThrowable($throwable, t('Unable to collect the ignored paths.'));
    }

    $event_dispatcher->dispatch($event);
    return $event->getResults();
  }

}
