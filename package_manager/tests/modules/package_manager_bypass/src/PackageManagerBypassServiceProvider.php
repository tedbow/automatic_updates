<?php

declare(strict_types = 1);

namespace Drupal\package_manager_bypass;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\Core\Site\Settings;
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

    $state = new Reference('state');
    $arguments = [
      $state,
      new Reference(Filesystem::class),
    ];
    if (Settings::get('package_manager_bypass_composer_stager', TRUE)) {
      $services = [
        'package_manager.beginner' => Beginner::class,
        'package_manager.stager' => Stager::class,
        'package_manager.committer' => Committer::class,
      ];
      foreach ($services as $id => $class) {
        $container->getDefinition($id)->setClass($class)->setArguments($arguments);
      }
    }

    $definition = $container->getDefinition('package_manager.path_locator')
      ->setClass(PathLocator::class);
    $arguments = $definition->getArguments();
    array_unshift($arguments, $state);
    $definition->setArguments($arguments);
  }

}
