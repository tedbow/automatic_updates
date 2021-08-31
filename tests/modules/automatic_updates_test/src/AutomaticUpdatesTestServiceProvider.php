<?php

namespace Drupal\automatic_updates_test;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Modifies service definitions for testing purposes.
 */
class AutomaticUpdatesTestServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    parent::alter($container);

    $service = 'automatic_updates.updater';
    if ($container->hasDefinition($service)) {
      $container->getDefinition($service)->setClass(TestUpdater::class);
    }
  }

}
