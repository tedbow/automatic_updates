<?php

namespace Drupal\automatic_updates\Validation;

use Drupal\automatic_updates\AutomaticUpdatesEvents;
use Drupal\automatic_updates\Event\UpdateEvent;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;

/**
 * Defines a manager to run readiness validation.
 */
class ReadinessValidationManager {

  /**
   * The key/value expirable storage.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface
   */
  protected $keyValueExpirable;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The number of hours to store results.
   *
   * @var int
   */
  protected $resultsTimeToLive;

  /**
   * Constructs a ReadinessValidationManager.
   *
   * @param \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface $key_value_expirable_factory
   *   The key/value expirable factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param int $results_time_to_live
   *   The number of hours to store results.
   */
  public function __construct(KeyValueExpirableFactoryInterface $key_value_expirable_factory, TimeInterface $time, int $results_time_to_live) {
    $this->keyValueExpirable = $key_value_expirable_factory->get('automatic_updates');
    $this->time = $time;
    $this->resultsTimeToLive = $results_time_to_live;
  }

  /**
   * Dispatches the readiness check event and stores the results.
   *
   * @return $this
   */
  public function run(): self {
    /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher */
    $dispatcher = \Drupal::service('event_dispatcher');
    $event = new UpdateEvent();
    $dispatcher->dispatch($event, AutomaticUpdatesEvents::READINESS_CHECK);
    $results = $event->getResults();
    $this->keyValueExpirable->setWithExpire(
      'readiness_validation_last_run',
      [
        'results' => $results,
        'listeners' => $this->getListenersAsString(AutomaticUpdatesEvents::READINESS_CHECK),
      ],
      $this->resultsTimeToLive * 60 * 60
    );
    $this->keyValueExpirable->set('readiness_check_timestamp', $this->time->getRequestTime());
    return $this;
  }

  /**
   * Gets all the listeners for a specific event as single string.
   *
   * @return string
   *   The listeners as a string.
   */
  protected function getListenersAsString(string $event_name): string {
    /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher */
    $dispatcher = \Drupal::service('event_dispatcher');
    $listeners = $dispatcher->getListeners($event_name);
    $string = '';
    foreach ($listeners as $listener) {
      /** @var object $object */
      $object = $listener[0];
      $method = $listener[1];
      $string .= '-' . get_class($object) . '::' . $method;
    }
    return $string;
  }

  /**
   * Dispatches the readiness check event if there no stored valid results.
   *
   * @return $this
   *
   * @see self::getResults()
   * @see self::getStoredValidResults()
   */
  public function runIfNoStoredResults(): self {
    if ($this->getResults() === NULL) {
      $this->run();
    }
    return $this;
  }

  /**
   * Gets the validation results from the last run.
   *
   * @param int|null $severity
   *   (optional) The severity for the results to return. Should be one of the
   *   SystemManager::REQUIREMENT_* constants.
   *
   * @return \Drupal\automatic_updates\Validation\ValidationResult[]|
   *   The validation result objects or NULL if no results are
   *   available or if the stored results are no longer valid.
   *
   * @see self::getStoredValidResults()
   */
  public function getResults(?int $severity = NULL): ?array {
    $results = $this->getStoredValidResults();
    if ($results !== NULL) {
      if ($severity !== NULL) {
        $results = array_filter($results, function ($result) use ($severity) {
          return $result->getSeverity() === $severity;
        });
      }
      return $results;
    }
    return NULL;
  }

  /**
   * Gets stored valid results, if any.
   *
   * The stored results are considered valid if the current listeners for the
   * readiness check event are the same as the last time the event was
   * dispatched.
   *
   * @return \Drupal\automatic_updates\Validation\ValidationResult[]|null
   *   The stored results if available and still valid, otherwise null.
   */
  protected function getStoredValidResults(): ?array {
    $last_run = $this->keyValueExpirable->get('readiness_validation_last_run');

    // If the listeners have not changed return the results.
    if ($last_run && $last_run['listeners'] === $this->getListenersAsString(AutomaticUpdatesEvents::READINESS_CHECK)) {
      return $last_run['results'];
    }
    return NULL;
  }

  /**
   * Gets the timestamp of the last run.
   *
   * @return int|null
   *   The timestamp of the last completed run, or NULL if no run has
   *   been completed.
   */
  public function getLastRunTime(): ?int {
    return $this->keyValueExpirable->get('readiness_check_timestamp');
  }

}
