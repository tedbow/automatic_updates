<?php

namespace Drupal\composer_stager_bypass;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Defines services to bypass Composer Stager's core functionality.
 */
class ComposerStagerBypassServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    parent::alter($container);

    $container->getDefinition('automatic_updates.beginner')
      ->setClass(Beginner::class);
    $container->getDefinition('automatic_updates.stager')
      ->setClass(Stager::class);
  }

}
