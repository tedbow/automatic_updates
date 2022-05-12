<?php

namespace Drupal\automatic_updates\Validator;

use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use Drupal\automatic_updates\CronUpdater;
use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\automatic_updates\ProjectInfo;
use Drupal\automatic_updates\Updater;
use Drupal\automatic_updates\VersionParsingTrait;
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
  use VersionParsingTrait;

  /**
   * Checks that the installed version of Drupal is updateable.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   */
  public function checkInstalledVersion(StageEvent $event): void {
    $stage = $event->getStage();

    // Only do these checks for automatic updates.
    if (!$stage instanceof Updater) {
      return;
    }

    $installed_version = $this->getInstalledVersion();

    if ($this->isDevSnapshotInstalled($event, $installed_version)) {
      return;
    }
    if ($stage instanceof CronUpdater && $stage->getMode() !== CronUpdater::DISABLED && !$this->isInstalledVersionStable($event, $installed_version)) {
      return;
    }
  }

  /**
   * Checks if the installed version of Drupal is a stable release.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   * @param string $installed_version
   *   The installed version of Drupal.
   *
   * @return bool
   *   TRUE if the installed version of Drupal is a stable release; otherwise
   *   FALSE.
   */
  private function isInstalledVersionStable(StageEvent $event, string $installed_version): bool {
    $extra = ExtensionVersion::createFromVersionString($installed_version)
      ->getVersionExtra();

    if ($extra) {
      $event->addError([
        $this->t('Drupal cannot be automatically updated during cron from its current version, @from_version, because Automatic Updates only supports updating from stable versions during cron.', [
          '@from_version' => $installed_version,
        ]),
      ]);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Checks that the target version of Drupal is valid.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   */
  public function checkTargetVersion(StageEvent $event): void {
    $stage = $event->getStage();

    // Only do these checks for automatic updates.
    if (!$stage instanceof Updater) {
      return;
    }

    $installed_version = $this->getInstalledVersion();
    $target_version = $this->getTargetVersion($event);

    if (!$this->isTargetVersionAcceptable($event, $target_version)) {
      return;
    }
    if ($this->isTargetVersionDowngrade($event, $installed_version, $target_version)) {
      return;
    }
    if ($this->isTargetMajorVersionDifferent($event, $installed_version, $target_version)) {
      return;
    }
    if (!$this->isAllowedMinorUpdate($event, $installed_version, $target_version)) {
      return;
    }

    if ($stage instanceof CronUpdater) {
      $mode = $stage->getMode();
      if ($mode === CronUpdater::DISABLED) {
        return;
      }
      if (!$this->isTargetVersionStable($event, $target_version)) {
        return;
      }
      if ($this->isMinorUpdate($event, $installed_version, $target_version)) {
        return;
      }
      if ($this->isTargetVersionTooFarAhead($event, $installed_version, $target_version)) {
        return;
      }

      if ($mode === CronUpdater::SECURITY && !$this->isTargetVersionSecurityRelease($event, $installed_version, $target_version)) {
        return;
      }
    }
  }

  /**
   * Checks if the target version of Drupal is a security release.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   * @param string $installed_version
   *   The installed version of Drupal.
   * @param string $target_version
   *   The target version of Drupal core.
   *
   * @return bool
   *   TRUE if the target version of Drupal core is a security release;
   *   otherwise FALSE.
   */
  private function isTargetVersionSecurityRelease(StageEvent $event, string $installed_version, string $target_version): bool {
    $releases = $this->getAvailableReleases($event);

    if (!$releases[$target_version]->isSecurityRelease()) {
      $event->addError([
        $this->t('Drupal cannot be automatically updated during cron from its current version, @from_version, to the recommended version, @to_version, because @to_version is not a security release.', [
          '@from_version' => $installed_version,
          '@to_version' => $target_version,
        ]),
      ]);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Checks if the target version is too far ahead to be automatically updated.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   * @param string $installed_version
   *   The installed version of Drupal.
   * @param string $target_version
   *   The target version of Drupal core.
   *
   * @return bool
   *   TRUE if the target version of Drupal core is more than one patch release
   *   ahead of the installed version; otherwise FALSE.
   */
  private function isTargetVersionTooFarAhead(StageEvent $event, string $installed_version, string $target_version): bool {
    $from_version = ExtensionVersion::createFromVersionString($installed_version);

    $supported_patch_version = $from_version->getMajorVersion() . '.' . $from_version->getMinorVersion() . '.' . (((int) static::getPatchVersion($installed_version)) + 1);
    if ($target_version !== $supported_patch_version) {
      $event->addError([
        $this->t('Drupal cannot be automatically updated during cron from its current version, @from_version, to the recommended version, @to_version, because Automatic Updates only supports 1 patch version update during cron.', [
          '@from_version' => $installed_version,
          '@to_version' => $target_version,
        ]),
      ]);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Checks if the target version of Drupal is a different minor version.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   * @param string $installed_version
   *   The installed version of Drupal.
   * @param string $target_version
   *   The target version of Drupal core.
   *
   * @return bool
   *   TRUE if the target version of Drupal is a different minor version;
   *   otherwise FALSE.
   */
  private function isMinorUpdate(StageEvent $event, string $installed_version, string $target_version): bool {
    $installed_minor = ExtensionVersion::createFromVersionString($installed_version)
      ->getMinorVersion();
    $target_minor = ExtensionVersion::createFromVersionString($target_version)
      ->getMinorVersion();

    if ($installed_minor === $target_minor) {
      return FALSE;
    }
    $event->addError([
      $this->t('Drupal cannot be automatically updated from its current version, @from_version, to the recommended version, @to_version, because automatic updates from one minor version to another are not supported during cron.', [
        '@from_version' => $installed_version,
        '@to_version' => $target_version,
      ]),
    ]);
    return TRUE;
  }

  /**
   * Checks if the target version of Drupal is a stable release.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   * @param string $target_version
   *   The target version of Drupal core.
   *
   * @return bool
   *   TRUE if the target version of Drupal core is a stable release; otherwise
   *   FALSE.
   */
  private function isTargetVersionStable(StageEvent $event, string $target_version): bool {
    $extra = ExtensionVersion::createFromVersionString($target_version)
      ->getVersionExtra();

    if ($extra) {
      $event->addError([
        $this->t('Drupal cannot be automatically updated during cron to the recommended version, @to_version, because Automatic Updates only supports updating to stable versions during cron.', [
          '@to_version' => $target_version,
        ]),
      ]);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Checks if the target version of Drupal is a different minor version.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   * @param string $installed_version
   *   The installed version of Drupal.
   * @param string $target_version
   *   The target version of Drupal core.
   *
   * @return bool
   *   TRUE if the target version of Drupal is a different minor version and
   *   updates to a different minor version are allowed; otherwise FALSE.
   */
  private function isAllowedMinorUpdate(StageEvent $event, string $installed_version, string $target_version): bool {
    $installed_minor = ExtensionVersion::createFromVersionString($installed_version)
      ->getMinorVersion();
    $target_minor = ExtensionVersion::createFromVersionString($target_version)
      ->getMinorVersion();

    $minor_updates_allowed = \Drupal::config('automatic_updates.settings')
      ->get('allow_core_minor_updates');

    if ($installed_minor === $target_minor || $minor_updates_allowed) {
      return TRUE;
    }

    $event->addError([
      $this->t('Drupal cannot be automatically updated from its current version, @from_version, to the recommended version, @to_version, because automatic updates from one minor version to another are not supported.', [
        '@from_version' => $installed_version,
        '@to_version' => $target_version,
      ]),
    ]);
    return FALSE;
  }

  /**
   * Checks if the target version of Drupal is a different major version.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   * @param string $installed_version
   *   The installed version of Drupal.
   * @param string $target_version
   *   The target version of Drupal core.
   *
   * @return bool
   *   TRUE if the target version of Drupal core is a different major version
   *   than the installed version; otherwise FALSE.
   */
  private function isTargetMajorVersionDifferent(StageEvent $event, string $installed_version, string $target_version): bool {
    $installed_major = ExtensionVersion::createFromVersionString($installed_version)
      ->getMajorVersion();
    $target_major = ExtensionVersion::createFromVersionString($target_version)
      ->getMajorVersion();

    if ($installed_major !== $target_major) {
      $event->addError([
        $this->t('Drupal cannot be automatically updated from its current version, @from_version, to the recommended version, @to_version, because automatic updates from one major version to another are not supported.', [
          '@from_version' => $installed_version,
          '@to_version' => $target_version,
        ]),
      ]);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Checks if the target version of Drupal is lower than the installed version.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   * @param string $installed_version
   *   The installed version of Drupal.
   * @param string $target_version
   *   The target version of Drupal core.
   *
   * @return bool
   *   TRUE if the target version of Drupal core is lower than the installed
   *   version; otherwise FALSE.
   */
  private function isTargetVersionDowngrade(StageEvent $event, string $installed_version, string $target_version): bool {
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
   * @param string|null $target_version
   *   The target version of Drupal, or NULL if it's not known.
   *
   * @return bool
   *   TRUE if the target version of Drupal is in the list of secure, supported
   *   releases; otherwise FALSE.
   */
  private function isTargetVersionAcceptable(StageEvent $event, ?string $target_version): bool {
    // If the target version isn't in the list of installable releases, then it
    // isn't secure and supported and we should flag an error.
    $releases = $this->getAvailableReleases($event);
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
    return $this->getTargetVersionFromAvailableReleases($event);
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
   *   The event object.
   * @param string $installed_version
   *   The installed version of Drupal.
   *
   * @return bool
   *   TRUE if the installed version of Drupal is a dev snapshot, otherwise
   *   FALSE.
   */
  private function isDevSnapshotInstalled(StageEvent $event, string $installed_version): bool {
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
