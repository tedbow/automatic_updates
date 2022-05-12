<?php

namespace Drupal\automatic_updates\Validator;

use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\automatic_updates\ProjectInfo;
use Drupal\automatic_updates\Updater;
use Drupal\Core\Extension\ExtensionVersion;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\StageEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates the installed and target versions of Drupal before an update.
 */
final class VersionValidator implements EventSubscriberInterface {

  /**
   * Checks that the installed version of Drupal is updateable.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   */
  public function checkInstalledVersion(StageEvent $event): void {
    // Only do these checks for automatic updates.
    if (!$event->getStage() instanceof Updater) {
      return;
    }

    if ($this->isDevSnapshotInstalled($event)) {
      return;
    }
  }

  /**
   * Checks if the installed version of Drupal is a dev snapshot.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   THe event object.
   *
   * @return bool
   *   TRUE if the installed version of Drupal is a dev snapshot, otherwise
   *   FALSE.
   */
  private function isDevSnapshotInstalled(StageEvent $event): bool {
    $installed_version = (new ProjectInfo('drupal'))->getInstalledVersion();
    $extra = ExtensionVersion::createFromVersionString($installed_version)
      ->getVersionExtra();

    if ($extra === 'dev') {
      $message = $this->t('Drupal cannot be automatically updated from the installed version, @installed_version, because automatic updates from a dev version to any other version are not supported.', [
        '@installed_version' => $installed_version,
      ]);
      $event->addError([$message]);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $listeners = ['checkInstalledVersion'];

    return [
      ReadinessCheckEvent::class => $listeners,
      PreCreateEvent::class => $listeners,
    ];
  }

}
