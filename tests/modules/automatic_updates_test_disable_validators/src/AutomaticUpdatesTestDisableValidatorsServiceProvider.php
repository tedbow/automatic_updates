<?php

namespace Drupal\automatic_updates_test_disable_validators;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\Core\Site\Settings;

/**
 * Disables specific readiness validators in the service container.
 */
class AutomaticUpdatesTestDisableValidatorsServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    parent::alter($container);

    $validators = Settings::get('automatic_updates_test_disable_validators', []);
    array_walk($validators, [$container, 'removeDefinition']);
  }

}
