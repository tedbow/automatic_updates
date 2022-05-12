<?php

namespace Drupal\automatic_updates\Validator;

use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use Drupal\automatic_updates\CronUpdater;
use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\automatic_updates\ProjectInfo;
use Drupal\automatic_updates\Updater;
use Drupal\Core\Extension\ExtensionVersion;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\StageEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates the installed and target versions of Drupal before an update.
 */
final class VersionValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

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
   * Checks that the target version of Drupal is valid.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   */
  public function checkTargetVersion(StageEvent $event): void {
    // Only do these checks for automatic updates.
    if (!$event->getStage() instanceof Updater) {
      return;
    }

    if (!$this->isTargetVersionAcceptable($event)) {
      return;
    }
    if ($this->isTargetVersionDowngrade($event)) {
      return;
    }
  }

  /**
   * Checks if the target version of Drupal is lower than the installed version.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   *
   * @return bool
   *   TRUE if the target version of Drupal core is lower than the installed
   *   version; otherwise FALSE.
   */
  private function isTargetVersionDowngrade(StageEvent $event): bool {
    $installed_version = $this->getInstalledVersion();
    $target_version = $this->getTargetVersion($event);

    if (Comparator::lessThan($target_version, $installed_version)) {
      $event->addError([
        $this->t('Update version @to_version is lower than @from_version, downgrading is not supported.', [
          '@to_version' => $target_version,
          '@from_version' => $installed_version,
        ]),
      ]);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Checks if the target version of Drupal is a secure, supported release.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   *
   * @return bool
   *   TRUE if the target version of Drupal is in the list of secure, supported
   *   releases; otherwise FALSE.
   */
  private function isTargetVersionAcceptable(StageEvent $event): bool {
    $target_version = $this->getTargetVersion($event);
    // If the target version isn't in the list of installable releases, then it
    // isn't secure and supported and we should flag an error.
    $releases = $this->getAvailableReleases();
    if (empty($releases) || !array_key_exists($target_version, $releases)) {
      $message = $this->t('Cannot update Drupal core to @target_version because it is not in the list of installable releases.', [
        '@target_version' => $target_version,
      ]);
      $event->addError([$message]);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Returns the target version of Drupal core.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   *
   * @return string|null
   *   The target version of Drupal core, or NULL if it could not be determined.
   */
  private function getTargetVersion(StageEvent $event): ?string {
    if ($event instanceof ReadinessCheckEvent) {
      $package_versions = $event->getPackageVersions();
    }
    else {
      /** @var \Drupal\automatic_updates\Updater $stage */
      $stage = $event->getStage();
      $package_versions = $stage->getPackageVersions()['production'];
    }

    if ($package_versions) {
      $core_package_name = key($updater->getActiveComposer()->getCorePackages());
      return $package_versions[$core_package_name];
    }
    return $this->getTargetVersionFromAvailableReleases($stage);
  }

  /**
   * Returns the target version of Drupal from the list of available releases.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   *
   * @return string|null
   *   The target version of Drupal core, or NULL if it could not be determined.
   *
   * @todo Expand this doc comment to explain how the list of available releases
   *   is fetched, sorted, and filtered through (i.e., must match the current
   *   minor). Maybe reference ProjectInfo::getInstallableReleases().
   */
  private function getTargetVersionFromAvailableReleases(StageEvent $event): ?string {
    $installed_version = $this->getInstalledVersion();

    foreach ($this->getAvailableReleases($event) as $possible_release) {
      $possible_version = $possible_release->getVersion();
      if (Semver::satisfies($possible_version, "~$installed_version")) {
        return $possible_version;
      }
    }
    return NULL;
  }

  /**
   * Returns the available releases of Drupal core.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   *
   * @return \Drupal\automatic_updates_9_3_shim\ProjectRelease[]
   *   The available releases of Drupal core, keyed by version number.
   *
   * @todo Expand this doc comment to explain what "installable" means (i.e.,
   *   reference ProjectInfo::getInstallableReleases()), and how the returned
   *   releases are sorted.
   */
  private function getAvailableReleases(StageEvent $event): array {
    $project_info = new ProjectInfo('drupal');
    $available_releases = $project_info->getInstallableReleases() ?? [];

    if ($event->getStage() instanceof CronUpdater) {
      $available_releases = array_reverse($available_releases);
    }
    return $available_releases;
  }

  /**
   * Returns the currently installed version of Drupal core.
   *
   * @return string|null
   *   The currently installed version of Drupal core, or NULL if it could not
   *   be determined.
   */
  private function getInstalledVersion(): ?string {
    return (new ProjectInfo('drupal'))->getInstalledVersion();
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
    $installed_version = $this->getInstalledVersion();
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
    return [
      ReadinessCheckEvent::class => [
        ['checkInstalledVersion'],
        ['checkTargetVersion'],
      ],
      PreCreateEvent::class => [
        ['checkInstalledVersion'],
        ['checkTargetVersion'],
      ],
    ];
  }

}
