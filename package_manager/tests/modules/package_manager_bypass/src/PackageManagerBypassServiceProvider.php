<?php

namespace Drupal\package_manager_bypass;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Reference;

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

    $container->register('package_manager_bypass.committer')
      ->setClass(Committer::class)
      ->setPublic(FALSE)
      ->setDecoratedService('package_manager.committer')
      ->setArguments([
        new Reference('package_manager_bypass.committer.inner'),
      ])
      ->setProperty('_serviceId', 'package_manager.committer');
  }

}
