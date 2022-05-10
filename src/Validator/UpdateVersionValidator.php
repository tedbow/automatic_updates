<?php

namespace Drupal\automatic_updates\Validator;

use Composer\Semver\Comparator;
use Drupal\automatic_updates\CronUpdater;
use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\automatic_updates\ProjectInfo;
use Drupal\automatic_updates\Updater;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ExtensionVersion;
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
    $from_version_string = $this->getCoreVersion();
    $variables = [
      '@to_version' => $to_version_string,
      '@from_version' => $from_version_string,
    ];
    $from_version = ExtensionVersion::createFromVersionString($from_version_string);

    // @todo Return multiple validation messages and summary in
    //   https://www.drupal.org/project/automatic_updates/issues/3272068.
    if (Comparator::lessThan($to_version_string, $from_version_string)) {
      return ValidationResult::createError([
        $this->t('Update version @to_version is lower than @from_version, downgrading is not supported.', $variables),
      ]);
    }
    $to_version = ExtensionVersion::createFromVersionString($to_version_string);
    if ($from_version->getMajorVersion() !== $to_version->getMajorVersion()) {
      return ValidationResult::createError([
        $this->t('Drupal cannot be automatically updated from its current version, @from_version, to the recommended version, @to_version, because automatic updates from one major version to another are not supported.', $variables),
      ]);
    }
    if ($from_version->getMinorVersion() !== $to_version->getMinorVersion()) {
      if (!$this->configFactory->get('automatic_updates.settings')->get('allow_core_minor_updates')) {
        return ValidationResult::createError([
          $this->t('Drupal cannot be automatically updated from its current version, @from_version, to the recommended version, @to_version, because automatic updates from one minor version to another are not supported.', $variables),
        ]);
      }
    }
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
