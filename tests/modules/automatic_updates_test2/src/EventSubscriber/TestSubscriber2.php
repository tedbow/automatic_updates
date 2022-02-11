<?php

namespace Drupal\automatic_updates_test2\EventSubscriber;

use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\automatic_updates_test\EventSubscriber\TestSubscriber1;
use Drupal\package_manager\Event\PreCreateEvent;

/**
 * A test readiness checker.
 */
class TestSubscriber2 extends TestSubscriber1 {

  protected const STATE_KEY = 'automatic_updates_test2.checker_results';

  public static function getSubscribedEvents() {
    $events[ReadinessCheckEvent::class][] = ['handleEvent', 4];
    $events[PreCreateEvent::class][] = ['handleEvent', 4];

    return $events;
  }

}
