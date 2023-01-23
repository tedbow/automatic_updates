<?php

declare(strict_types = 1);

namespace Drupal\package_manager_bypass;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\Core\Site\Settings;
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

    $state = new Reference('state');
    if (Settings::get('package_manager_bypass_composer_stager', TRUE)) {
      $container->getDefinition('package_manager.stager')->setClass(Stager::class)->setArguments([$state]);
    }

    $definition = $container->getDefinition('package_manager.path_locator')
      ->setClass(PathLocator::class);
    $arguments = $definition->getArguments();
    array_unshift($arguments, $state);
    $definition->setArguments($arguments);
  }

}
