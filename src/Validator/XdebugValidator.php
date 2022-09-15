<?php

namespace Drupal\automatic_updates\Validator;

use Drupal\automatic_updates\CronUpdater;
use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\automatic_updates\Updater;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Performs validation if Xdebug is enabled.
 *
 * @internal
 *   This is an internal part of Automatic Updates and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
final class XdebugValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Performs validation if Xdebug is enabled.
   *
   * If Xdebug is enabled, cron updates are prevented. For other updates, only
   * a warning is flagged during readiness checks.
   *
   * @param \Drupal\package_manager\Event\PreOperationStageEvent $event
   *   The event object.
   */
  public function checkForXdebug(PreOperationStageEvent $event): void {
    // We only want to do this check if the stage belongs to Automatic Updates.
    if (!($event->getStage() instanceof Updater)) {
      return;
    }

    if (function_exists('xdebug_break')) {
      $messages = [
        $this->t('Xdebug is enabled, which may cause timeout errors.'),
      ];

      if ($event->getStage() instanceof CronUpdater) {
        // Cron updates are not allowed if Xdebug is enabled.
        $event->addError($messages);
      }
      elseif ($event instanceof ReadinessCheckEvent) {
        // For non-cron updates provide a warning but do not stop updates from
        // executing.
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
