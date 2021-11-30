<?php

namespace Drupal\package_manager;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\package_manager\EventSubscriber\UpdateDataSubscriber;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Defines dynamic container services for Package Manager.
 */
class PackageManagerServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);

    if (array_key_exists('update', $container->getParameter('container.modules'))) {
      $container->register('package_manager.update_data_subscriber')
        ->setClass(UpdateDataSubscriber::class)
        ->setArguments([
          new Reference('update.manager'),
        ])
        ->addTag('event_subscriber');
    }
  }

}
