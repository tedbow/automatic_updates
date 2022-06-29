<?php

namespace Drupal\automatic_updates_extensions_test;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceModifierInterface;

/**
 * Service provider to use test updater service.
 */
class AutomaticUpdatesExtensionsTestServiceProvider implements ServiceModifierInterface {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    if ($container->hasDefinition('automatic_updates_extensions.updater')) {
      $container->getDefinition('automatic_updates_extensions.updater')
        ->setClass(TestExtensionUpdater::class);

    }
  }

}
