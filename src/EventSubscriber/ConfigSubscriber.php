<?php

namespace Drupal\automatic_updates\EventSubscriber;

use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Clears stored validation results after certain config changes.
 *
 * @todo Move this functionality into ReadinessValidationManager when
 *   https://www.drupal.org/i/3275317#comment-14482995 is resolved.
 */
class ConfigSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      ConfigEvents::SAVE => 'onConfigSave',
    ];
  }

  /**
   * Reacts when config is saved.
   *
   * @param \Drupal\Core\Config\ConfigCrudEvent $event
   *   The event object.
   */
  public function onConfigSave(ConfigCrudEvent $event): void {
    if ($event->getConfig()->getName() === 'package_manager.settings' && $event->isChanged('executables.composer')) {
      \Drupal::service('automatic_updates.readiness_validation_manager')
        ->clearStoredResults();
    }
  }

}
