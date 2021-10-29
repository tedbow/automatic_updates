<?php

namespace Drupal\automatic_updates_test2\ReadinessChecker;

use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\automatic_updates_test\ReadinessChecker\TestChecker1;
use Drupal\package_manager\Event\PreCreateEvent;

/**
 * A test readiness checker.
 */
class TestChecker2 extends TestChecker1 {

  protected const STATE_KEY = 'automatic_updates_test2.checker_results';

  public static function getSubscribedEvents() {
    $events[ReadinessCheckEvent::class][] = ['addResults', 4];
    $events[PreCreateEvent::class][] = ['addResults', 4];

    return $events;
  }

}
