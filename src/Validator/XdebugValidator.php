<?php

namespace Drupal\automatic_updates\Validator;

use Drupal\automatic_updates\CronUpdater;
use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;
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
   * @param \Drupal\package_manager\Event\PreOperationStageEvent $event
   *   The event object.
   */
  public function checkForXdebug(PreOperationStageEvent $event): void {
    if (function_exists('xdebug_break')) {
      $messages = [
        $this->t('Xdebug is enabled, which may cause timeout errors.'),
      ];

      if ($event->getStage() instanceof CronUpdater && $event instanceof PreCreateEvent) {
        $event->addError($messages);
      }
      else {
        $event->addWarning($messages);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      ReadinessCheckEvent::class => 'checkForXdebug',
      PreCreateEvent::class => 'checkForXdebug',
    ];
  }

}
