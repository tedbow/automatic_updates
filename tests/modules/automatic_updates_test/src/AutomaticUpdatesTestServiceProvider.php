<?php

namespace Drupal\automatic_updates_test;

use Drupal\automatic_updates_test\Validator\TestPendingUpdatesValidator;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Modifies container services for testing purposes.
 */
class AutomaticUpdatesTestServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    parent::alter($container);

    $container->getDefinition('package_manager.validator.pending_updates')
      ->setClass(TestPendingUpdatesValidator::class);
  }

}
