<?php

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

    // By default, bypass the Composer Stager library. This can be disabled for
    // tests that want to use the real library, but only need to disable
    // validators.
    if (Settings::get('package_manager_bypass_stager', TRUE)) {
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

    // Allow functional tests to disable specific validators as necessary.
    // Kernel tests can override the ::register() method and modify the
    // container directly.
    $validators = Settings::get('package_manager_bypass_validators', []);
    array_walk($validators, [$container, 'removeDefinition']);
  }

}
