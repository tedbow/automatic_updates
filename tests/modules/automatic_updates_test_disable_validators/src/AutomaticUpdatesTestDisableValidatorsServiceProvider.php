<?php

namespace Drupal\automatic_updates_test_disable_validators;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\Core\Site\Settings;

/**
 * Allows specific validators to be disabled by site settings.
 *
 * This should only really be used by functional tests. Kernel tests should
 * override their ::register() method to remove service definitions; build tests
 * should stay out of the API/services layer unless absolutely necessary.
 */
class AutomaticUpdatesTestDisableValidatorsServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    $validators = Settings::get('automatic_updates_disable_validators', []);
    array_walk($validators, [$container, 'removeDefinition']);
  }

}
