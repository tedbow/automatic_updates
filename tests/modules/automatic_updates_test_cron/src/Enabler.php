<?php

namespace Drupal\automatic_updates_test_cron;

use Drupal\automatic_updates\CronUpdater;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Enables automatic updates during cron.
 *
 * @todo Remove this when TUF integration is stable.
 */
class Enabler implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      KernelEvents::REQUEST => 'enableCron',
    ];
  }

  /**
   * Enables automatic updates during cron.
   */
  public function enableCron(): void {
    if (class_exists(CronUpdater::class)) {
      $reflector = new \ReflectionClass(CronUpdater::class);
      $reflector = $reflector->getProperty('disabled');
      $reflector->setAccessible(TRUE);
      $reflector->setValue(NULL, FALSE);
    }
  }

}
