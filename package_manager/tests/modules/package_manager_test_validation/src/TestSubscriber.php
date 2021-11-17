<?php

namespace Drupal\package_manager_test_validation;

use Drupal\Core\State\StateInterface;
use Drupal\package_manager\Event\PostApplyEvent;
use Drupal\package_manager\Event\PostCreateEvent;
use Drupal\package_manager\Event\PostDestroyEvent;
use Drupal\package_manager\Event\PostRequireEvent;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreDestroyEvent;
use Drupal\package_manager\Event\PreRequireEvent;
use Drupal\package_manager\Event\StageEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Defines an event subscriber for testing validation of Package Manager events.
 */
class TestSubscriber implements EventSubscriberInterface {

  /**
   * The key to use store the test results.
   *
   * @var string
   */
  protected const STATE_KEY = 'package_manager_test_validation';

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Creates a TestSubscriber object.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(StateInterface $state) {
    $this->state = $state;
  }

  /**
   * Sets validation results for a specific event.
   *
   * This method is static to enable setting the expected results before this
   * module is enabled.
   *
   * @param \Drupal\package_manager\ValidationResult[]|null $results
   *   The validation results, or NULL to delete stored results.
   * @param string $event
   *   The event class.
   */
  public static function setTestResult(?array $results, string $event): void {
    $key = static::STATE_KEY . '.' . $event;

    $state = \Drupal::state();
    if (isset($results)) {
      $state->set($key, $results);
    }
    else {
      $state->delete($key);
    }
  }

  /**
   * Sets an exception to throw for a specific event.
   *
   * This method is static to enable setting the expected results before this
   * module is enabled.
   *
   * @param \Throwable|null $error
   *   The exception to throw, or NULL to delete a stored exception.
   * @param string $event
   *   The event class.
   */
  public static function setException(?\Throwable $error, string $event): void {
    $key = static::STATE_KEY . '.' . $event;

    $state = \Drupal::state();
    if (isset($error)) {
      $state->set($key, $error);
    }
    else {
      $state->delete($key);
    }
  }

  /**
   * Adds validation results to a stage event.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   */
  public function addResults(StageEvent $event): void {
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
    $priority = defined('PACKAGE_MANAGER_TEST_VALIDATOR_PRIORITY') ? PACKAGE_MANAGER_TEST_VALIDATOR_PRIORITY : 5;

    return [
      PreCreateEvent::class => ['addResults', $priority],
      PostCreateEvent::class => ['addResults', $priority],
      PreRequireEvent::class => ['addResults', $priority],
      PostRequireEvent::class => ['addResults', $priority],
      PreApplyEvent::class => ['addResults', $priority],
      PostApplyEvent::class => ['addResults', $priority],
      PreDestroyEvent::class => ['addResults', $priority],
      PostDestroyEvent::class => ['addResults', $priority],
    ];
  }

}
