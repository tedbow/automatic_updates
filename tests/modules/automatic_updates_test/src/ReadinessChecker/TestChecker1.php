<?php

namespace Drupal\automatic_updates_test\ReadinessChecker;

use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\package_manager_test_validation\TestSubscriber;

/**
 * A test readiness checker.
 */
class TestChecker1 extends TestSubscriber {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events = parent::getSubscribedEvents();
    $events[ReadinessCheckEvent::class] = reset($events);
    return $events;
  }

}
