<?php

namespace Drupal\automatic_updates\Validator;

use Composer\Semver\Semver;
use Drupal\automatic_updates\CronUpdater;
use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\automatic_updates\ProjectInfo;
use Drupal\automatic_updates\Updater;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
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
   * The class resolver service.
   *
   * @var \Drupal\Core\DependencyInjection\ClassResolverInterface
   */
  private $classResolver;

  /**
   * Constructs a VersionValidator object.
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
   * @param string $target_version
   *   The target version of Drupal core.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   The error messages returned from the first policy rule which rejected
   *   the given target version.
   *
   * @see \Drupal\automatic_updates\Validator\VersionPolicy\RuleBase::validate()
   */
  public function validateVersion(Updater $updater, string $target_version): array {
    // These rules always apply:
    // - The installed version must be a tagged release, not a dev snapshot.
    // - The target version must be a known installable release.
    // - Downgrading is never allowed.
    // - Updating across major versions is never allowed.
    $rules = [
      'TaggedReleaseInstalled',
      'TargetVersionInstallable',
      'ForbidDowngrade',
      'MajorVersionMatch',
    ];

    if ($updater instanceof CronUpdater) {
      $mode = $updater->getMode();

      // These rules always apply for cron updates:
      // - The installed version must be stable (no alphas, betas, or RCs).
      // - The target version must be stable too.
      // - Updating across minor version is never allowed.
      // - The target version must be no more than one patch release away from
      //   the installed version.
      if ($mode !== CronUpdater::DISABLED) {
        $rules[] = 'StableReleaseInstalled';
        $rules[] = 'TargetVersionStable';
        $rules[] = 'ForbidMinorUpdates';
        $rules[] = 'TargetVersionPatchLevel';
      }
      // If only security updates are allowed during cron, then the target
      // release must also be a security release.
      if ($mode === CronUpdater::SECURITY) {
        $rules[] = 'TargetSecurityRelease';
      }
    }
    else {
      $rules[] = 'MinorUpdatesEnabled';
    }

    // Convert the list of policy rules into fully qualified class names.
    $map = function (string $class): string {
      return __NAMESPACE__ . "\VersionPolicy\\$class";
    };
    $rules = array_map($map, $rules);

    // Invoke each rule, stopping when one returns error messages.
    // @todo Implement a better mechanism for looping through all validators and
    //   collecting all messages.
    foreach ($rules as $rule) {
      $messages = $this->classResolver
        ->getInstanceFromDefinition($rule)
        ->validate($updater, $this->getInstalledVersion(), $target_version);

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
  public function checkTargetVersion(StageEvent $event): void {
    $stage = $event->getStage();

    // Only do these checks for automatic updates.
    if (!$stage instanceof Updater) {
      return;
    }

    // If we can't figure out the target version of Drupal core, bail out.
    $target_version = $this->getTargetVersion($event);
    if (empty($target_version)) {
      return;
    }

    $messages = $this->validateVersion($stage, $target_version);
    if ($messages) {
      $summary = count($messages) > 1
        ? $this->t('Drupal cannot be automatically updated.')
        : NULL;
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
      $core_package_name = key($updater->getStage()->getActiveComposer()->getCorePackages());
      return $package_versions[$core_package_name];
    }
    return $this->getTargetVersionFromAvailableReleases($updater);
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
   *
   * @internal
   *   This is an internal part of Automatic Updates' version policy for
   *   Drupal core. It may be changed or removed at any time without warning.
   *   External code should not call this method.
   */
  public static function getAvailableReleases(Updater $updater): array {
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
      ReadinessCheckEvent::class => 'checkTargetVersion',
      PreCreateEvent::class => 'checkTargetVersion',
    ];
  }

}
