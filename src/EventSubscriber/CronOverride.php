<?php

namespace Drupal\automatic_updates\EventSubscriber;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;

/**
 * This class overrides the default system warning and error limits for cron.
 *
 * It notifies site administrators on a more strict time frame if cron has not
 * recently run.
 */
class CronOverride implements ConfigFactoryOverrideInterface {

  /**
   * Warn at 3 hours.
   */
  const WARNING_THRESHOLD = 10800;

  /**
   * Error at 6 hours.
   */
  const ERROR_THRESHOLD = 21600;

  /**
   * {@inheritdoc}
   */
  public function loadOverrides($names) {
    $overrides = [];
    if (in_array('system.cron', $names, TRUE)) {
      $overrides['system.cron']['threshold'] = [
        'requirements_warning' => $this::WARNING_THRESHOLD,
        'requirements_error' => $this::ERROR_THRESHOLD,
      ];
    }
    return $overrides;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheSuffix() {
    return 'CronOverride';
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata($name) {
    return new CacheableMetadata();
  }

  /**
   * {@inheritdoc}
   */
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) {
    return NULL;
  }

}
