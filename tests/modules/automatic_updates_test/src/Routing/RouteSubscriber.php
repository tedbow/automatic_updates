<?php

namespace Drupal\automatic_updates_test\Routing;

use Drupal\automatic_updates_test\Controller\TestUpdateController;
use Drupal\automatic_updates_test\Form\TestUpdateReady;
use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Alters route definitions for testing purposes.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    $collection->get('automatic_updates.confirmation_page')
      ->setDefault('_form', TestUpdateReady::class);
    $collection->get('automatic_updates.finish')
      ->setDefault('_controller', TestUpdateController::class . '::onFinish');
  }

}
