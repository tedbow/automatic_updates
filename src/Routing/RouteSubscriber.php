<?php

namespace Drupal\automatic_updates\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Modifies route definitions.
 *
 * @internal
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    $disabled_routes = [
      'update.theme_update',
      'system.theme_install',
      'update.module_update',
      'update.module_install',
      'update.status',
      'update.report_update',
      'update.report_install',
      'update.settings',
      'system.status',
      'update.confirmation_page',
    ];
    foreach ($disabled_routes as $route) {
      $route = $collection->get($route);
      if ($route) {
        $route->setOption('_automatic_updates_readiness_messages', 'skip');
      }
    }
  }

}
