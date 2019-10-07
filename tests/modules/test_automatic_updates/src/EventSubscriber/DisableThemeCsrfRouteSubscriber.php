<?php

namespace Drupal\test_automatic_updates\EventSubscriber;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Disable theme CSRF route subscriber.
 */
class DisableThemeCsrfRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    // Disable CSRF so we can easily enable themes in tests.
    if ($route = $collection->get('system.theme_set_default')) {
      $route->setRequirements(['_permission' => 'administer themes']);
    }
  }

}
