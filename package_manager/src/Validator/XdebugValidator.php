<?php

namespace Drupal\package_manager\Validator;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\package_manager\Event\StatusCheckEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Performs validation if Xdebug is enabled.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
final class XdebugValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Adds warning if Xdebug is enabled.
   *
   * @param \Drupal\package_manager\Event\StatusCheckEvent $event
   *   The event object.
   */
  public function checkForXdebug(StatusCheckEvent $event): void {
    if (function_exists('xdebug_break')) {
      $messages = [
        $this->t('Xdebug is enabled, which may have a negative performance impact on Package Manager and any modules that use it.'),
      ];
      $event->addWarning($messages);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      StatusCheckEvent::class => 'checkForXdebug',
    ];
  }

}
