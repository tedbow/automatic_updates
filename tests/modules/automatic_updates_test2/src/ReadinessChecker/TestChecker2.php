<?php

namespace Drupal\automatic_updates_test2\ReadinessChecker;

use Drupal\automatic_updates\AutomaticUpdatesEvents;
use Drupal\automatic_updates_test\ReadinessChecker\TestChecker1;

/**
 * A test readiness checker.
 */
class TestChecker2 extends TestChecker1 {

  protected const STATE_KEY = 'automatic_updates_test2.checker_results';

  public static function getSubscribedEvents() {
    $events[AutomaticUpdatesEvents::READINESS_CHECK][] = ['runPreChecks', 4];
    $events[AutomaticUpdatesEvents::PRE_START][] = ['runStartChecks', 4];

    return $events;
  }

}
