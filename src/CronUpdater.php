<?php

namespace Drupal\automatic_updates;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Defines a service that updates via cron.
 *
 * @internal
 *   This class implements logic specific to Automatic Updates' cron hook
 *   implementation. It should not be called directly.
 */
class CronUpdater extends Updater {

  /**
   * All automatic updates are disabled.
   *
   * @var string
   */
  public const DISABLED = 'disable';

  /**
   * Only perform automatic security updates.
   *
   * @var string
   */
  public const SECURITY = 'security';

  /**
   * All automatic updates are enabled.
   *
   * @var string
   */
  public const ALL = 'patch';

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a CronUpdater object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   * @param mixed ...$arguments
   *   Additional arguments to pass to the parent constructor.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory, ...$arguments) {
    parent::__construct(...$arguments);
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('automatic_updates');
  }

  /**
   * Handles updates during cron.
   */
  public function handleCron(): void {
    $level = $this->configFactory->get('automatic_updates.settings')
      ->get('cron');

    // If automatic updates are disabled, bail out.
    if ($level === static::DISABLED) {
      return;
    }

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

    // If automatic updates are only enabled for security releases, bail out if
    // the recommended release is not a security release.
    if ($level === static::SECURITY && !$recommended_release->isSecurityRelease()) {
      return;
    }

    // @todo Use the queue to add update jobs allowing jobs to span multiple
    //   cron runs.
    $recommended_version = $recommended_release->getVersion();
    try {
      $this->begin([
        'drupal' => $recommended_version,
      ]);
      $this->stage();
      $this->apply();
      $this->destroy();
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
