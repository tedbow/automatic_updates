<?php

namespace Drupal\automatic_updates\Validator;

use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Flags a warning if Xdebug is enabled.
 *
 * @internal
 *   This is an internal part of Automatic Updates and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
final class XdebugValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Flags a warning if Xdebug is enabled.
   *
   * @param \Drupal\automatic_updates\Event\ReadinessCheckEvent $event
   *   The event object.
   */
  public function checkForXdebug(ReadinessCheckEvent $event): void {
    if (extension_loaded('xdebug')) {
      $event->addWarning([
        $this->t('Xdebug is enabled, which may cause timeout errors.'),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      ReadinessCheckEvent::class => 'checkForXdebug',
    ];
  }

}
