<?php

namespace Drupal\package_manager_bypass;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Defines services to bypass Package Manager's core functionality.
 */
class PackageManagerBypassServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    parent::alter($container);

    $services = [
      'package_manager.beginner' => Beginner::class,
      'package_manager.stager' => Stager::class,
      'package_manager.committer' => Committer::class,
    ];
    $arguments = [
      new Reference('state'),
      new Reference(Filesystem::class),
    ];
    foreach ($services as $id => $class) {
      $container->getDefinition($id)->setClass($class)->setArguments($arguments);
    }

    $container->getDefinition('package_manager.path_locator')
      ->setClass(PathLocator::class)
      ->addArgument($arguments[0]);
  }

}
