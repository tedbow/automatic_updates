<?php

namespace Drupal\automatic_updates_9_3_shim;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Modifies container service definitions.
 */
class AutomaticUpdates93ShimServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    parent::alter($container);

    $service_id = 'update.update_hook_registry_factory';
    if ($container->hasDefinition($service_id)) {
      $container->getDefinition($service_id)
        ->setClass(UpdateHookRegistryFactory::class);
    }
    else {
      $container->register($service_id, UpdateHookRegistryFactory::class)
        ->addMethodCall('setContainer', [
          new Reference('service_container'),
        ]);
    }

    $service_id = 'update.update_hook_registry';
    if ($container->hasDefinition($service_id)) {
      $container->getDefinition($service_id)
        ->setClass(UpdateHookRegistry::class);
    }
    else {
      $container->register($service_id, UpdateHookRegistry::class)
        ->setFactory([
          new Reference($service_id . '_factory'),
          'create',
        ]);
    }

    $container->getDefinition('module_installer')
      ->setClass(ModuleInstaller::class);
  }

}
