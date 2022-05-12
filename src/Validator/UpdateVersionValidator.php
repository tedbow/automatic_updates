<?php

namespace Drupal\automatic_updates\Validator;

use Composer\Semver\Semver;
use Drupal\automatic_updates\CronUpdater;
use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\automatic_updates\ProjectInfo;
use Drupal\automatic_updates\Updater;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;
use Drupal\package_manager\Event\StageEvent;
use Drupal\package_manager\Stage;
use Drupal\package_manager\ValidationResult;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates that core updates are within a supported version range.
 *
 * @internal
 *   This class is an internal part of the module's update handling and
 *   should not be used by external code.
 */
class UpdateVersionValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a UpdateVersionValidation object.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The translation service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   */
  public function __construct(TranslationInterface $translation, ConfigFactoryInterface $config_factory) {
    $this->setStringTranslation($translation);
    $this->configFactory = $config_factory;
  }

  /**
   * Returns the running core version, according to the Update module.
   *
   * @return string
   *   The running core version as known to the Update module.
   */
  protected function getCoreVersion(): string {
    return (new ProjectInfo('drupal'))->getInstalledVersion();
  }

  /**
   * Validates that core is being updated within an allowed version range.
   *
   * @param \Drupal\package_manager\Event\PreOperationStageEvent $event
   *   The event object.
   */
  public function checkUpdateVersion(PreOperationStageEvent $event): void {
    if (!static::isStageSupported($event->getStage())) {
      return;
    }
    if ($to_version = $this->getUpdateVersion($event)) {
      if ($result = $this->getValidationResult($to_version)) {
        $event->addError($result->getMessages(), $result->getSummary());
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      PreCreateEvent::class => 'checkUpdateVersion',
      ReadinessCheckEvent::class => 'checkUpdateVersion',
    ];
  }

  /**
   * Gets the update version.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event.
   *
   * @return string|null
   *   The version that the site will update to if any, otherwise NULL.
   */
  protected function getUpdateVersion(StageEvent $event): ?string {
    /** @var \Drupal\automatic_updates\Updater $updater */
    $updater = $event->getStage();
    if ($event instanceof ReadinessCheckEvent) {
      $package_versions = $event->getPackageVersions();
      if (!$package_versions) {
        // During readiness checks we might not have a version to update to.
        // Use the next possible update version to run checks against.
        return $this->getNextPossibleUpdateVersion();
      }
    }
    else {
      // If the stage has begun its life cycle, we expect it knows the desired
      // package versions.
      $package_versions = $updater->getPackageVersions()['production'];
    }
    if ($package_versions) {
      // All the core packages will be updated to the same version, so it
      // doesn't matter which specific package we're looking at.
      $core_package_name = key($updater->getActiveComposer()->getCorePackages());
      return $package_versions[$core_package_name];
    }
    return NULL;
  }

  /**
   * Gets the next possible update version, if any.
   *
   * @return string|null
   *   The next possible update version if available, otherwise NULL.
   */
  protected function getNextPossibleUpdateVersion(): ?string {
    $project_info = new ProjectInfo('drupal');
    $installed_version = $project_info->getInstalledVersion();
    if ($possible_releases = $project_info->getInstallableReleases()) {
      foreach ($possible_releases as $possible_release) {
        $possible_version = $possible_release->getVersion();
        if (Semver::satisfies($possible_release->getVersion(), "~$installed_version")) {
          return $possible_version;
        }
      }
    }
    return NULL;
  }

  /**
   * Determines if a version is valid.
   *
   * @param string $version
   *   The version string.
   *
   * @return bool
   *   TRUE if the version is valid (i.e., the site can update to it), otherwise
   *   FALSE.
   */
  public function isValidVersion(string $version): bool {
    return empty($this->getValidationResult($version));
  }

  /**
   * Validates if an update to a specific version is allowed.
   *
   * @param string $to_version_string
   *   The version to update to.
   *
   * @return \Drupal\package_manager\ValidationResult|null
   *   NULL if the update is allowed, otherwise returns a validation result with
   *   the reason why the update is not allowed.
   */
  protected function getValidationResult(string $to_version_string): ?ValidationResult {
    return NULL;
  }

  /**
   * Determines if a stage is supported by this validator.
   *
   * @param \Drupal\package_manager\Stage $stage
   *   The stage to check.
   *
   * @return bool
   *   TRUE if the stage is supported by this validator, otherwise FALSE.
   */
  protected static function isStageSupported(Stage $stage): bool {
    // We only want to do this check if the stage belongs to Automatic Updates,
    // and it is not a cron update.
    // @see \Drupal\automatic_updates\Validator\CronUpdateVersionValidator
    return $stage instanceof Updater && !$stage instanceof CronUpdater;
  }

}
