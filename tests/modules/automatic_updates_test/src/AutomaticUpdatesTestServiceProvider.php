<?php

declare(strict_types = 1);

namespace Drupal\automatic_updates_test;

use Drupal\automatic_updates\CronUpdateRunner;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

class AutomaticUpdatesTestServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    parent::alter($container);
    if (\Drupal::state()->get(self::class . '-runner') && $container->hasDefinition('automatic_updates.cron_update_runner')) {
      $container->getDefinition('automatic_updates.cron_update_runner')->setClass(TestCronUpdateRunner::class);
    }
  }

  public static function useTestCronUpdateRunner(bool $use = TRUE) {
    \Drupal::state()->set(self::class . '-runner', $use);

  }

}
