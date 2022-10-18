<?php

namespace Drupal\automatic_updates_test2\EventSubscriber;

use Drupal\automatic_updates_test\EventSubscriber\TestSubscriber1;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\StatusCheckEvent;

/**
 * A test readiness checker.
 */
class TestSubscriber2 extends TestSubscriber1 {

  protected const STATE_KEY = 'automatic_updates_test2.checker_results';

  public static function getSubscribedEvents() {
    $events[StatusCheckEvent::class][] = ['handleEvent', 4];
    $events[PreCreateEvent::class][] = ['handleEvent', 4];

    return $events;
  }

}
