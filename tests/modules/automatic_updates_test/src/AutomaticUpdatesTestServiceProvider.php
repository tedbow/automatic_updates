<?php

namespace Drupal\automatic_updates_test;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Modifies container services for testing.
 */
class AutomaticUpdatesTestServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    parent::alter($container);

    $service_id = 'automatic_updates.validator.staged_database_updates';
    if ($container->hasDefinition($service_id)) {
      $container->getDefinition($service_id)
        ->setClass(StagedDatabaseUpdateValidator::class)
        ->addMethodCall('setState', [
          new Reference('state'),
        ]);
    }
  }

}
