<?php

namespace Drupal\automatic_updates_test;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Defines a service provider for testing automatic updates.
 */
class AutomaticUpdatesTestServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    parent::alter($container);

    $modules = $container->getParameter('container.modules');
    if (isset($modules['automatic_updates'])) {
      // Swap in our special updater implementation, which can be rigged to
      // throw errors during various points in the update process in order to
      // test error handling during updates.
      $container->getDefinition('automatic_updates.updater')
        ->setClass(TestUpdater::class);
    }
  }

}
