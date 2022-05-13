<?php

namespace Drupal\automatic_updates\Validator;

use Composer\Semver\Semver;
use Drupal\automatic_updates\CronUpdater;
use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\automatic_updates\ProjectInfo;
use Drupal\automatic_updates\Updater;
use Drupal\automatic_updates\Validator\Version\AllowedMinorUpdateValidator;
use Drupal\automatic_updates\Validator\Version\DevVersionInstalledValidator;
use Drupal\automatic_updates\Validator\Version\DowngradeValidator;
use Drupal\automatic_updates\Validator\Version\MajorVersionMatchValidator;
use Drupal\automatic_updates\Validator\Version\MinorUpdateValidator;
use Drupal\automatic_updates\Validator\Version\StableInstalledVersionValidator;
use Drupal\automatic_updates\Validator\Version\StableTargetVersionValidator;
use Drupal\automatic_updates\Validator\Version\TargetSecurityReleaseValidator;
use Drupal\automatic_updates\Validator\Version\TargetVersionInstallableValidator;
use Drupal\automatic_updates\Validator\Version\TargetVersionPatchLevelValidator;
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

  protected function collectMessages(array $validators, ...$arguments): array {
    $all_messages = [];

    foreach ($validators as $validator) {
      $messages = $this->classResolver->getInstanceFromDefinition($validator)
        ->validate(...$arguments);
      if ($messages) {
        $all_messages = array_merge($all_messages, $messages);
        break;
      }
    }
    return $all_messages;
  }

  public function validateVersion(Updater $updater, string $target_version): array {
    $validators = [
      DevVersionInstalledValidator::class,
      TargetVersionInstallableValidator::class,
      DowngradeValidator::class,
      MajorVersionMatchValidator::class,
      AllowedMinorUpdateValidator::class,
    ];

    if ($updater instanceof CronUpdater) {
      $mode = $updater->getMode();

      if ($mode !== CronUpdater::DISABLED) {
        array_pop($validators);
        $validators[] = StableInstalledVersionValidator::class;
        $validators[] = StableTargetVersionValidator::class;
        $validators[] = MinorUpdateValidator::class;
        $validators[] = TargetVersionPatchLevelValidator::class;
      }
      if ($mode === CronUpdater::SECURITY) {
        $validators[] = TargetSecurityReleaseValidator::class;
      }
    }

    return $this->collectMessages($validators, $updater, $this->getInstalledVersion(), $target_version);
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
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      ReadinessCheckEvent::class => 'checkTargetVersion',
      PreCreateEvent::class => 'checkTargetVersion',
    ];
  }

}
