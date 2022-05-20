<?php

namespace Drupal\automatic_updates\Validator;

use Composer\Semver\Semver;
use Drupal\automatic_updates\CronUpdater;
use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\automatic_updates\ProjectInfo;
use Drupal\automatic_updates\Updater;
use Drupal\automatic_updates\Validator\VersionPolicy\ForbidDowngrade;
use Drupal\automatic_updates\Validator\VersionPolicy\ForbidMinorUpdates;
use Drupal\automatic_updates\Validator\VersionPolicy\MajorVersionMatch;
use Drupal\automatic_updates\Validator\VersionPolicy\MinorUpdatesEnabled;
use Drupal\automatic_updates\Validator\VersionPolicy\StableReleaseInstalled;
use Drupal\automatic_updates\Validator\VersionPolicy\ForbidDevSnapshot;
use Drupal\automatic_updates\Validator\VersionPolicy\TargetSecurityRelease;
use Drupal\automatic_updates\Validator\VersionPolicy\TargetVersionInstallable;
use Drupal\automatic_updates\Validator\VersionPolicy\TargetVersionStable;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\StageEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates the installed and target versions of Drupal before an update.
 *
 * @internal
 *   This class is an internal part of the module's update handling and should
 *   not be used by external code.
 */
final class VersionPolicyValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The class resolver service.
   *
   * @var \Drupal\Core\DependencyInjection\ClassResolverInterface
   */
  private $classResolver;

  /**
   * Constructs a VersionPolicyValidator object.
   *
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $class_resolver
   *   The class resolver service.
   */
  public function __construct(ClassResolverInterface $class_resolver) {
    $this->classResolver = $class_resolver;
  }

  /**
   * Validates a target version of Drupal core.
   *
   * @param \Drupal\automatic_updates\Updater $updater
   *   The updater which will perform the update.
   * @param string|null $target_version
   *   The target version of Drupal core, or NULL if it is not known.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   The error messages returned from the first policy rule which rejected
   *   the given target version.
   *
   * @see \Drupal\automatic_updates\Validator\VersionPolicy\RuleBase::validate()
   */
  public function validateVersion(Updater $updater, ?string $target_version): array {
    // Check that the installed version of Drupal isn't a dev snapshot.
    $rules = [
      ForbidDevSnapshot::class,
    ];

    // If the target version is known, it must conform to a few basic rules.
    if ($target_version) {
      // The target version must be newer than the installed version...
      $rules[] = ForbidDowngrade::class;
      // ...and in the same major version as the installed version...
      $rules[] = MajorVersionMatch::class;
      // ...and it must be a known, secure, installable release.
      $rules[] = TargetVersionInstallable::class;
    }

    // If this is a cron update, we may need to do additional checks.
    if ($updater instanceof CronUpdater) {
      $mode = $updater->getMode();

      if ($mode !== CronUpdater::DISABLED) {
        // If cron updates are enabled, the installed version must be stable;
        // no alphas, betas, or RCs.
        $rules[] = StableReleaseInstalled::class;

        // If the target version is known, more rules apply.
        if ($target_version) {
          // The target version must be stable too...
          $rules[] = TargetVersionStable::class;
          // ...and it must be in the same minor as the installed version.
          $rules[] = ForbidMinorUpdates::class;

          // If only security updates are allowed during cron, the target
          // version must be a security release.
          if ($mode === CronUpdater::SECURITY) {
            $rules[] = TargetSecurityRelease::class;
          }
        }
      }
    }
    // If this is not a cron update, and we know the target version, minor
    // version updates are allowed if configuration says so.
    elseif ($target_version) {
      $rules[] = MinorUpdatesEnabled::class;
    }

    $installed_version = $this->getInstalledVersion();
    $available_releases = $this->getAvailableReleases($updater);

    // Invoke each rule in the order that they were added to $rules, stopping
    // when one returns error messages.
    // @todo Return all the error messages in https://www.drupal.org/i/3281379.
    foreach ($rules as $rule) {
      $messages = $this->classResolver
        ->getInstanceFromDefinition($rule)
        ->validate($installed_version, $target_version, $available_releases);

      if ($messages) {
        return $messages;
      }
    }
    return [];
  }

  /**
   * Checks that the target version of Drupal is valid.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   */
  public function checkVersion(StageEvent $event): void {
    $stage = $event->getStage();

    // Only do these checks for automatic updates.
    if (!$stage instanceof Updater) {
      return;
    }
    $target_version = $this->getTargetVersion($event);

    $messages = $this->validateVersion($stage, $target_version);
    if ($messages) {
      $summary = $this->t('Updating from Drupal @installed_version to @target_version is not allowed.', [
        '@installed_version' => $this->getInstalledVersion(),
        '@target_version' => $target_version,
      ]);
      $event->addError($messages, $summary);
    }
  }

  /**
   * Returns the target version of Drupal core.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   *
   * @return string|null
   *   The target version of Drupal core, or NULL if it could not be determined.
   *
   * @todo Throw an exception in certain (maybe all) situations if we cannot
   *   figure out the target version in https://www.drupal.org/i/3280180.
   */
  private function getTargetVersion(StageEvent $event): ?string {
    $updater = $event->getStage();

    if ($event instanceof ReadinessCheckEvent) {
      $package_versions = $event->getPackageVersions();
    }
    else {
      $package_versions = $updater->getPackageVersions()['production'];
    }

    if ($package_versions) {
      $core_package_name = key($updater->getActiveComposer()->getCorePackages());
      return $package_versions[$core_package_name];
    }
    elseif ($event instanceof ReadinessCheckEvent) {
      return $this->getTargetVersionFromAvailableReleases($updater);
    }
    else {
      return NULL;
    }
  }

  /**
   * Returns the target version of Drupal from the list of available releases.
   *
   * @param \Drupal\automatic_updates\Updater $updater
   *   The updater which will perform the update.
   *
   * @return string|null
   *   The target version of Drupal core, or NULL if it could not be determined.
   *
   * @todo Expand this doc comment to explain how the list of available releases
   *   is fetched, sorted, and filtered through (i.e., must match the current
   *   minor). Maybe reference ProjectInfo::getInstallableReleases().
   */
  private function getTargetVersionFromAvailableReleases(Updater $updater): ?string {
    $installed_version = $this->getInstalledVersion();

    foreach (self::getAvailableReleases($updater) as $possible_release) {
      $possible_version = $possible_release->getVersion();
      if (Semver::satisfies($possible_version, "~$installed_version")) {
        return $possible_version;
      }
    }
    return NULL;
  }

  /**
   * Returns the available releases of Drupal core for a given updater.
   *
   * @param \Drupal\automatic_updates\Updater $updater
   *   The updater which will perform the update.
   *
   * @return \Drupal\automatic_updates_9_3_shim\ProjectRelease[]
   *   The available releases of Drupal core, keyed by version number and in
   *   descending order (i.e., newest first). Will be in ascending order (i.e.,
   *   oldest first) if $updater is the cron updater.
   *
   * @see \Drupal\automatic_updates\ProjectInfo::getInstallableReleases()
   */
  private function getAvailableReleases(Updater $updater): array {
    $project_info = new ProjectInfo('drupal');
    $available_releases = $project_info->getInstallableReleases() ?? [];

    if ($updater instanceof CronUpdater) {
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
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      ReadinessCheckEvent::class => 'checkVersion',
      PreCreateEvent::class => 'checkVersion',
    ];
  }

}
