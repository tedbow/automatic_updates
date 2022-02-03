<?php

namespace Drupal\automatic_updates\Validation;

use Drupal\automatic_updates\CronUpdater;
use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\automatic_updates\Updater;
use Drupal\automatic_updates\UpdateRecommender;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\package_manager\Event\PostApplyEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Defines a manager to run readiness validation.
 */
class ReadinessValidationManager implements EventSubscriberInterface {

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
   * The event dispatcher service.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The number of hours to store results.
   *
   * @var int
   */
  protected $resultsTimeToLive;


  /**
   * The updater service.
   *
   * @var \Drupal\automatic_updates\Updater
   */
  protected $updater;

  /**
   * The cron updater service.
   *
   * @var \Drupal\automatic_updates\CronUpdater
   */
  protected $cronUpdater;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  /**
   * Constructs a ReadinessValidationManager.
   *
   * @param \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface $key_value_expirable_factory
   *   The key/value expirable factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher
   *   The event dispatcher service.
   * @param \Drupal\automatic_updates\Updater $updater
   *   The updater service.
   * @param \Drupal\automatic_updates\CronUpdater $cron_updater
   *   The cron updater service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config
   *   The config factory service.
   * @param int $results_time_to_live
   *   The number of hours to store results.
   */
  public function __construct(KeyValueExpirableFactoryInterface $key_value_expirable_factory, TimeInterface $time, EventDispatcherInterface $dispatcher, Updater $updater, CronUpdater $cron_updater, ConfigFactoryInterface $config, int $results_time_to_live) {
    $this->keyValueExpirable = $key_value_expirable_factory->get('automatic_updates');
    $this->time = $time;
    $this->eventDispatcher = $dispatcher;
    $this->updater = $updater;
    $this->cronUpdater = $cron_updater;
    $this->config = $config;
    $this->resultsTimeToLive = $results_time_to_live;
  }

  /**
   * Dispatches the readiness check event and stores the results.
   *
   * @return $this
   */
  public function run(): self {
    $recommender = new UpdateRecommender();
    $release = $recommender->getRecommendedRelease(TRUE);
    // If updates will run during cron, use the cron updater service provided by
    // this module. This will allow subscribers to ReadinessCheckEvent to run
    // specific validation for conditions that only affect cron updates.
    if ($this->config->get('automatic_updates.settings')->get('cron') == CronUpdater::DISABLED) {
      $stage = $this->updater;
    }
    else {
      $stage = $this->cronUpdater;
    }

    $project_versions = $release ? ['drupal' => $release->getVersion()] : [];
    $event = new ReadinessCheckEvent($stage, $project_versions);
    $this->eventDispatcher->dispatch($event);
    $results = $event->getResults();
    $this->keyValueExpirable->setWithExpire(
      'readiness_validation_last_run',
      $results,
      $this->resultsTimeToLive * 60 * 60
    );
    $this->keyValueExpirable->set('readiness_check_timestamp', $this->time->getRequestTime());
    return $this;
  }

  /**
   * Dispatches the readiness check event if there no stored valid results.
   *
   * @return $this
   *
   * @see self::getResults()
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
   * @return \Drupal\package_manager\ValidationResult[]|
   *   The validation result objects or NULL if no results are
   *   available or if the stored results are no longer valid.
   */
  public function getResults(?int $severity = NULL): ?array {
    $results = $this->keyValueExpirable->get('readiness_validation_last_run');
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
   * Deletes any stored readiness validation results.
   */
  public function clearStoredResults(): void {
    $this->keyValueExpirable->delete('readiness_validation_last_run');
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

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      PostApplyEvent::class => 'clearStoredResults',
    ];
  }

}
