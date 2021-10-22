<?php

namespace Drupal\automatic_updates\Validator;

use Composer\Semver\Semver;
use Drupal\automatic_updates\AutomaticUpdatesEvents;
use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\automatic_updates\Event\UpdateEvent;
use Drupal\automatic_updates\Validation\ValidationResult;
use Drupal\Core\Extension\ExtensionVersion;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates that core updates are within a supported version range.
 */
class UpdateVersionValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Constructs a UpdateVersionValidation object.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The translation service.
   */
  public function __construct(TranslationInterface $translation) {
    $this->setStringTranslation($translation);
  }

  /**
   * Returns the running core version, according to the Update module.
   *
   * @return string
   *   The running core version as known to the Update module.
   */
  protected function getCoreVersion(): string {
    // We need to call these functions separately, because
    // update_get_available() will include the file that contains
    // update_calculate_project_data().
    $available_updates = update_get_available();
    $available_updates = update_calculate_project_data($available_updates);
    return $available_updates['drupal']['existing_version'];
  }

  /**
   * Validates that core is not being updated to another minor or major version.
   *
   * @param \Drupal\automatic_updates\Event\PreStartEvent|\Drupal\automatic_updates\Event\ReadinessCheckEvent $event
   *   The event object.
   */
  public function checkUpdateVersion(UpdateEvent $event): void {
    $from_version_string = $this->getCoreVersion();
    $from_version = ExtensionVersion::createFromVersionString($from_version_string);
    $core_package_names = $event->getActiveComposer()->getCorePackageNames();
    // All the core packages will be updated to the same version, so it doesn't
    // matter which specific package we're looking at.
    $core_package_name = reset($core_package_names);
    $to_version_string = $event->getPackageVersions()[$core_package_name];
    $to_version = ExtensionVersion::createFromVersionString($to_version_string);
    if (Semver::satisfies($to_version_string, "< $from_version_string")) {
      $messages[] = $this->t('Update version @to_version is lower than @from_version, downgrading is not supported.', [
        '@to_version' => $to_version_string,
        '@from_version' => $from_version_string,
      ]);
      $error = ValidationResult::createError($messages);
      $event->addValidationResult($error);
    }
    elseif ($from_version->getMajorVersion() !== $to_version->getMajorVersion()) {
      $error = ValidationResult::createError([
        $this->t('Updating from one major version to another is not supported.'),
      ]);
      $event->addValidationResult($error);
    }
    elseif ($from_version->getMinorVersion() !== $to_version->getMinorVersion()) {
      $error = ValidationResult::createError([
        $this->t('Updating from one minor version to another is not supported.'),
      ]);
      $event->addValidationResult($error);
    }

  }

  /**
   * Validates readiness check event.
   *
   * @param \Drupal\automatic_updates\Event\ReadinessCheckEvent $event
   *   The readiness check event object.
   */
  public function checkReadinessUpdateVersion(ReadinessCheckEvent $event): void {
    // During readiness checks, we might not know the desired package versions,
    // which means there's nothing to validate.
    if ($event->getPackageVersions()) {
      $this->checkUpdateVersion($event);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      AutomaticUpdatesEvents::PRE_START => 'checkUpdateVersion',
      AutomaticUpdatesEvents::READINESS_CHECK => 'checkReadinessUpdateVersion',
    ];
  }

}
