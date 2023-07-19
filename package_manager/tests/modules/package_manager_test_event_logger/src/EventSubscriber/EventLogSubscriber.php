<?php

declare(strict_types = 1);

namespace Drupal\package_manager_test_event_logger\EventSubscriber;

use Drupal\package_manager\Event\PostApplyEvent;
use Drupal\package_manager\Event\PostCreateEvent;
use Drupal\package_manager\Event\PostDestroyEvent;
use Drupal\package_manager\Event\PostRequireEvent;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreDestroyEvent;
use Drupal\package_manager\Event\PreRequireEvent;
use Drupal\package_manager\Event\StageEvent;
use Drupal\package_manager\Event\StatusCheckEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Defines an event subscriber to test logging during events in Package Manager.
 */
final class EventLogSubscriber implements EventSubscriberInterface {

  /**
   * Logs all events in the stage life cycle.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   */
  public function logEventInfo(StageEvent $event): void {
    $channel = $event instanceof StatusCheckEvent ? 'package_manager_test_status_event_logger' : 'package_manager_test_lifecycle_event_logger';
    \Drupal::logger($channel)->info("$channel-start: Event: " . get_class($event) . ', Stage instance of: ' . get_class($event->stage) . ":$channel-end");
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // This subscriber should run before every other validator, because the
    // purpose of this subscriber is to log all dispatched events.
    // @see \Drupal\package_manager\Validator\BaseRequirementsFulfilledValidator
    // @see \Drupal\package_manager\Validator\BaseRequirementValidatorTrait
    // @see \Drupal\package_manager\Validator\EnvironmentSupportValidator
    return [
      PreCreateEvent::class => ['logEventInfo', PHP_INT_MAX],
      PostCreateEvent::class => ['logEventInfo', PHP_INT_MAX],
      PreRequireEvent::class => ['logEventInfo', PHP_INT_MAX],
      PostRequireEvent::class => ['logEventInfo', PHP_INT_MAX],
      PreApplyEvent::class => ['logEventInfo', PHP_INT_MAX],
      PostApplyEvent::class => ['logEventInfo', PHP_INT_MAX],
      PreDestroyEvent::class => ['logEventInfo', PHP_INT_MAX],
      PostDestroyEvent::class => ['logEventInfo', PHP_INT_MAX],
      StatusCheckEvent::class => ['logEventInfo', PHP_INT_MAX],
    ];
  }

}
