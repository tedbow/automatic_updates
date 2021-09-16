<?php

namespace Drupal\automatic_updates;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a service that updates via cron.
 *
 * @internal
 *   This class implements logic specific to Automatic Updates' cron hook
 *   implementation. It should not be called directly.
 */
class CronUpdater implements ContainerInjectionInterface {

  /**
   * The updater service.
   *
   * @var \Drupal\automatic_updates\Updater
   */
  protected $updater;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a CronUpdater object.
   *
   * @param \Drupal\automatic_updates\Updater $updater
   *   The updater service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   */
  public function __construct(Updater $updater, LoggerChannelFactoryInterface $logger_factory) {
    $this->updater = $updater;
    $this->logger = $logger_factory->get('automatic_updates');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('automatic_updates.updater'),
      $container->get('logger.factory')
    );
  }

  /**
   * Handles updates during cron.
   */
  public function handleCron(): void {
    $recommender = new UpdateRecommender();
    try {
      $recommended_release = $recommender->getRecommendedRelease(TRUE);
    }
    catch (\Throwable $e) {
      $this->logger->error($e->getMessage());
      return;
    }

    // If we're already up-to-date, there's nothing else we need to do.
    if ($recommended_release === NULL) {
      return;
    }

    $project = $recommender->getProjectInfo();
    if (empty($project['existing_version'])) {
      $this->logger->error('Unable to determine the current version of Drupal core.');
      return;
    }

    // @todo Use the queue to add update jobs allowing jobs to span multiple
    //   cron runs.
    $recommended_version = $recommended_release->getVersion();
    try {
      $this->updater->begin([
        'drupal' => $recommended_version,
      ]);
      $this->updater->stage();
      $this->updater->commit();
      $this->updater->clean();
    }
    catch (\Throwable $e) {
      $this->logger->error($e->getMessage());
      return;
    }

    $this->logger->info(
      'Drupal core has been updated from %previous_version to %update_version',
      [
        '%previous_version' => $project['existing_version'],
        '%update_version' => $recommended_version,
      ]
    );
  }

}