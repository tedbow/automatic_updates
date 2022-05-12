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
use Drupal\Core\StringTranslation\TranslatableMarkup;
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

    $messages = array_merge(
      $this->checkTargetVersionIsInstallable($event, $target_version),
      $this->checkForDowngrade($installed_version, $target_version),
      $this->checkForMajorVersionMatch($installed_version, $target_version),
      $this->checkForAllowedMinorUpdate($installed_version, $target_version)
    );
    if ($stage instanceof CronUpdater) {
      $mode = $stage->getMode();

      if ($mode !== CronUpdater::DISABLED) {
        $messages = array_merge(
          $messages,
          $this->checkTargetVersionIsStable($target_version),
          $this->checkForMinorUpdate($installed_version, $target_version),
          $this->checkTargetVersionWithinPatchThreshold($installed_version, $target_version)
        );
      }
      if ($mode === CronUpdater::SECURITY) {
        $messages = array_merge(
          $messages,
          $this->checkTargetVersionIsSecurityRelease($event, $target_version)
        );
      }
    }

    $variables = [
      '@installed_version' => $installed_version,
      '@target_version' => $target_version,
    ];

    $map = function (TranslatableMarkup $message) use ($variables): TranslatableMarkup {
      // @codingStandardsIgnoreLine
      return new TranslatableMarkup($message->getUntranslatedString(), $message->getArguments() + $variables, $message->getOptions(), $this->getStringTranslation());
    };
    $messages = array_map($map, $messages);

    if ($messages) {
      $event->addError($messages, $this->t('Drupal cannot be updated from the current version, @installed_version, to @target_version.', $variables));
    }
  }

  /**
   * Checks if the target version of Drupal is a security release.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   * @param string $target_version
   *   The target version of Drupal core.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   The error messages, if any.
   */
  private function checkTargetVersionIsSecurityRelease(StageEvent $event, string $target_version): array {
    $releases = $this->getAvailableReleases($event);

    if (!$releases[$target_version]->isSecurityRelease()) {
      return [
        $this->t('Drupal cannot be automatically updated during cron from its current version, @installed_version, to the recommended version, @target_version, because @target_version is not a security release.'),
      ];
    }
    return [];
  }

  /**
   * Checks if the target version is too far ahead to be automatically updated.
   *
   * @param string $installed_version
   *   The installed version of Drupal.
   * @param string $target_version
   *   The target version of Drupal core.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   The error messages, if any.
   */
  private function checkTargetVersionWithinPatchThreshold(string $installed_version, string $target_version): array {
    $from_version = ExtensionVersion::createFromVersionString($installed_version);

    $supported_patch_version = $from_version->getMajorVersion() . '.' . $from_version->getMinorVersion() . '.' . (((int) static::getPatchVersion($installed_version)) + 1);
    if ($target_version !== $supported_patch_version) {
      return [
        $this->t('Drupal cannot be automatically updated during cron from its current version, @installed_version, to the recommended version, @target_version, because Automatic Updates only supports 1 patch version update during cron.'),
      ];
    }
    return [];
  }

  /**
   * Checks if the target version of Drupal is a different minor version.
   *
   * @param string $installed_version
   *   The installed version of Drupal.
   * @param string $target_version
   *   The target version of Drupal core.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   The error messages, if any.
   */
  private function checkForMinorUpdate(string $installed_version, string $target_version): array {
    $installed_minor = ExtensionVersion::createFromVersionString($installed_version)
      ->getMinorVersion();
    $target_minor = ExtensionVersion::createFromVersionString($target_version)
      ->getMinorVersion();

    if ($installed_minor === $target_minor) {
      return [];
    }
    return [
      $this->t('Drupal cannot be automatically updated from its current version, @installed_version, to the recommended version, @target_version, because automatic updates from one minor version to another are not supported during cron.'),
    ];
  }

  /**
   * Checks if the target version of Drupal is a stable release.
   *
   * @param string $target_version
   *   The target version of Drupal core.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   The error messages, if any.
   */
  private function checkTargetVersionIsStable(string $target_version): array {
    $extra = ExtensionVersion::createFromVersionString($target_version)
      ->getVersionExtra();

    if ($extra) {
      return [
        $this->t('Drupal cannot be automatically updated during cron to the recommended version, @target_version, because Automatic Updates only supports updating to stable versions during cron.'),
      ];
    }
    return [];
  }

  /**
   * Checks if the target version of Drupal is a different minor version.
   *
   * @param string $installed_version
   *   The installed version of Drupal.
   * @param string $target_version
   *   The target version of Drupal core.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   The error messages, if any.
   */
  private function checkForAllowedMinorUpdate(string $installed_version, string $target_version): array {
    $installed_minor = ExtensionVersion::createFromVersionString($installed_version)
      ->getMinorVersion();
    $target_minor = ExtensionVersion::createFromVersionString($target_version)
      ->getMinorVersion();

    $minor_updates_allowed = \Drupal::config('automatic_updates.settings')
      ->get('allow_core_minor_updates');

    if ($installed_minor === $target_minor || $minor_updates_allowed) {
      return [];
    }

    return [
      $this->t('Drupal cannot be automatically updated from its current version, @installed_version, to the recommended version, @target_version, because automatic updates from one minor version to another are not supported.'),
    ];
  }

  /**
   * Checks if the target version of Drupal is a different major version.
   *
   * @param string $installed_version
   *   The installed version of Drupal.
   * @param string $target_version
   *   The target version of Drupal core.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   The error messages, if any.
   */
  private function checkForMajorVersionMatch(string $installed_version, string $target_version): array {
    $installed_major = ExtensionVersion::createFromVersionString($installed_version)
      ->getMajorVersion();
    $target_major = ExtensionVersion::createFromVersionString($target_version)
      ->getMajorVersion();

    if ($installed_major !== $target_major) {
      return [
        $this->t('Drupal cannot be automatically updated from its current version, @installed_version, to the recommended version, @target_version, because automatic updates from one major version to another are not supported.'),
      ];
    }
    return [];
  }

  /**
   * Checks if the target version of Drupal is lower than the installed version.
   *
   * @param string $installed_version
   *   The installed version of Drupal.
   * @param string $target_version
   *   The target version of Drupal core.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   The error messages, if any.
   */
  private function checkForDowngrade(string $installed_version, string $target_version): array {
    if (Comparator::lessThan($target_version, $installed_version)) {
      return [
        $this->t('Update version @target_version is lower than @installed_version, downgrading is not supported.'),
      ];
    }
    return [];
  }

  /**
   * Checks if the target version of Drupal is a secure, supported release.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   * @param string|null $target_version
   *   The target version of Drupal, or NULL if it's not known.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   The error messages, if any.
   */
  private function checkTargetVersionIsInstallable(StageEvent $event, ?string $target_version): array {
    // If the target version isn't in the list of installable releases, then it
    // isn't secure and supported and we should flag an error.
    $releases = $this->getAvailableReleases($event);
    if (empty($releases) || !array_key_exists($target_version, $releases)) {
      $message = $this->t('Cannot update Drupal core to @target_version because it is not in the list of installable releases.', [
        '@target_version' => $target_version,
      ]);
      return [$message];
    }
    return [];
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
      $core_package_name = key($event->getStage()->getActiveComposer()->getCorePackages());
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
