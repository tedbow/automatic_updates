<?php

namespace Drupal\package_manager_bypass;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Defines services to bypass Package Manager's core functionality.
 */
class PackageManagerBypassServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    parent::alter($container);

    $container->getDefinition('package_manager.beginner')
      ->setClass(Beginner::class);
    $container->getDefinition('package_manager.stager')
      ->setClass(Stager::class);
  }

}
