<?php

namespace Drupal\automatic_updates_test\ReadinessChecker;

use Drupal\automatic_updates\AutomaticUpdatesEvents;
use Drupal\automatic_updates\Event\UpdateEvent;
use Drupal\Core\State\StateInterface;
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
   * @param \Drupal\automatic_updates\Validation\ValidationResult[]|\Throwable $checker_results
   *   The test validation results, or an exception to throw.
   * @param string $event_name
   *   (optional )The event name. Defaults to
   *   AutomaticUpdatesEvents::READINESS_CHECK.
   */
  public static function setTestResult($checker_results, string $event_name = AutomaticUpdatesEvents::READINESS_CHECK): void {
    \Drupal::state()->set(static::STATE_KEY . ".$event_name", $checker_results);
  }

  /**
   * Adds test result to an update event from a state setting.
   *
   * @param \Drupal\automatic_updates\Event\UpdateEvent $event
   *   The update event.
   * @param string $state_key
   *   The state key.
   */
  protected function addResults(UpdateEvent $event, string $state_key): void {
    $results = $this->state->get($state_key, []);
    if ($results instanceof \Throwable) {
      throw $results;
    }
    foreach ($results as $result) {
      $event->addValidationResult($result);
    }
  }

  /**
   * Adds test results for the readiness check event.
   *
   * @param \Drupal\automatic_updates\Event\UpdateEvent $event
   *   The update event.
   */
  public function runPreChecks(UpdateEvent $event): void {
    $this->addResults($event, static::STATE_KEY . "." . AutomaticUpdatesEvents::READINESS_CHECK);
  }

  /**
   * Adds test results for the pre-commit event.
   *
   * @param \Drupal\automatic_updates\Event\UpdateEvent $event
   *   The update event.
   */
  public function runPreCommitChecks(UpdateEvent $event): void {
    $this->addResults($event, static::STATE_KEY . "." . AutomaticUpdatesEvents::PRE_COMMIT);
  }

  /**
   * Adds test results for the pre-start event.
   *
   * @param \Drupal\automatic_updates\Event\UpdateEvent $event
   *   The update event.
   */
  public function runStartChecks(UpdateEvent $event): void {
    $this->addResults($event, static::STATE_KEY . "." . AutomaticUpdatesEvents::PRE_START);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $priority = defined('AUTOMATIC_UPDATES_TEST_SET_PRIORITY') ? AUTOMATIC_UPDATES_TEST_SET_PRIORITY : 5;
    $events[AutomaticUpdatesEvents::READINESS_CHECK][] = ['runPreChecks', $priority];
    $events[AutomaticUpdatesEvents::PRE_START][] = ['runStartChecks', $priority];
    $events[AutomaticUpdatesEvents::PRE_COMMIT][] = ['runPreCommitChecks', $priority];
    return $events;
  }

}
