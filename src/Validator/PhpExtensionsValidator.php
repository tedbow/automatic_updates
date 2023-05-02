<?php

declare(strict_types = 1);

namespace Drupal\automatic_updates\Validator;

use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\StatusCheckEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\automatic_updates\CronUpdateStage;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;
use Drupal\package_manager\Validator\PhpExtensionsValidator as PackageManagerPhpExtensionsValidator;

/**
 * Prevents unattended updates if Xdebug is enabled.
 *
 * @internal
 *   This is an internal part of Automatic Updates and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
final class PhpExtensionsValidator extends PackageManagerPhpExtensionsValidator implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public function validateXdebug(PreOperationStageEvent $event): void {
    if ($this->isExtensionLoaded('xdebug') && $event->stage instanceof CronUpdateStage) {
      $event->addError([$this->t("Xdebug is enabled, currently Cron Updates are not allowed while it is enabled. If Xdebug is not disabled you will not receive security and other updates during cron.")]);
    }
    elseif ($event instanceof StatusCheckEvent) {
      parent::validateXdebug($event);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = parent::getSubscribedEvents();
    $events[PreCreateEvent::class] = 'validateXdebug';
    $events[PreApplyEvent::class] = 'validateXdebug';
    return $events;
  }

}
