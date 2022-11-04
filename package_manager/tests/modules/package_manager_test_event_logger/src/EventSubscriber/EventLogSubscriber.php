<?php

namespace Drupal\package_manager_test_event_logger\EventSubscriber;

use Drupal\Component\Datetime\TimeInterface;
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
class EventLogSubscriber implements EventSubscriberInterface {

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * Creates a EventLogSubscriber object.
   *
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(TimeInterface $time) {
    $this->time = $time;
  }

  /**
   * Adds validation results to a stage event.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   */
  public function logEventInfo(StageEvent $event): void {
    \Drupal::logger('package_manager')->info('Event: ' . get_class($event) . ', Stage instance of ' . get_class($event->getStage()) . ', Request time: ' . $this->time->getRequestTime());
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      PreCreateEvent::class => ['logEventInfo'],
      PostCreateEvent::class => ['logEventInfo'],
      PreRequireEvent::class => ['logEventInfo'],
      PostRequireEvent::class => ['logEventInfo'],
      PreApplyEvent::class => ['logEventInfo'],
      PostApplyEvent::class => ['logEventInfo'],
      PreDestroyEvent::class => ['logEventInfo'],
      PostDestroyEvent::class => ['logEventInfo'],
      StatusCheckEvent::class => ['logEventInfo'],
    ];
  }

}
