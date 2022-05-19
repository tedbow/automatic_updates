<?php

namespace Drupal\automatic_updates\Validator;

use Drupal\automatic_updates\ProjectInfo;
use Drupal\automatic_updates\Updater;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\package_manager\Event\PreCreateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates that the target release of Drupal core is secure and supported.
 *
 * @internal
 *   This class is an internal part of the module's update handling and
 *   should not be used by external code.
 */
class UpdateReleaseValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Checks that the target version of Drupal core is secure and supported.
   *
   * @param \Drupal\package_manager\Event\PreCreateEvent $event
   *   The event object.
   */
  public function checkRelease(PreCreateEvent $event): void {
    $stage = $event->getStage();
    // This check only works with Automatic Updates.
    if (!$stage instanceof Updater) {
      return;
    }

    $package_versions = $stage->getPackageVersions();
    // The updater will only update Drupal core, so all production dependencies
    // will be Drupal core packages.
    $target_version = reset($package_versions['production']);

    // If the target version isn't in the list of installable releases, then it
    // isn't secure and supported and we should flag an error.
    $releases = (new ProjectInfo('drupal'))->getInstallableReleases();
    if (empty($releases) || !array_key_exists($target_version, $releases)) {
      $message = $this->t('Cannot update Drupal core to @target_version because it is not in the list of installable releases.', [
        '@target_version' => $target_version,
      ]);
      $event->addError([$message]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      PreCreateEvent::class => 'checkRelease',
    ];
  }

}
