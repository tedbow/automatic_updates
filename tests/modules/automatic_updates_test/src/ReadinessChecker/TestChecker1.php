<?php

namespace Drupal\automatic_updates_test\ReadinessChecker;

use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\Core\State\StateInterface;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * A test readiness checker.
 */
class TestChecker1 implements EventSubscriberInterface {

  /**
   * The key to use store the test results.
   */
  protected const STATE_KEY = 'automatic_updates_test.checker_results';

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Creates a TestChecker object.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(StateInterface $state) {
    $this->state = $state;
  }

  /**
   * Sets messages for this readiness checker.
   *
   * This method is static to enable setting the expected messages before the
   * test module is enabled.
   *
   * @param \Drupal\package_manager\ValidationResult[]|\Throwable|null $checker_results
   *   The test validation results, or an exception to throw, or NULL to delete
   *   stored results.
   * @param string $event_name
   *   (optional )The event name. Defaults to
   *   ReadinessCheckEvent::class.
   */
  public static function setTestResult($checker_results, string $event_name = ReadinessCheckEvent::class): void {
    $key = static::STATE_KEY . ".$event_name";

    if (isset($checker_results)) {
      \Drupal::state()->set($key, $checker_results);
    }
    else {
      \Drupal::state()->delete($key);
    }
  }

  /**
   * Adds test result to an update event from a state setting.
   *
   * @param object $event
   *   The update event.
   */
  public function addResults(object $event): void {
    $results = $this->state->get(static::STATE_KEY . '.' . get_class($event), []);
    if ($results instanceof \Throwable) {
      throw $results;
    }
    foreach ($results as $result) {
      $event->addValidationResult($result);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $priority = defined('AUTOMATIC_UPDATES_TEST_SET_PRIORITY') ? AUTOMATIC_UPDATES_TEST_SET_PRIORITY : 5;
    $events[ReadinessCheckEvent::class][] = ['addResults', $priority];
    $events[PreCreateEvent::class][] = ['addResults', $priority];
    $events[PreApplyEvent::class][] = ['addResults', $priority];
    return $events;
  }

}
