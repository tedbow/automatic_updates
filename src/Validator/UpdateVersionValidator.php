<?php

namespace Drupal\automatic_updates\Validator;

use Drupal\automatic_updates\AutomaticUpdatesEvents;
use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\automatic_updates\Event\UpdateEvent;
use Drupal\automatic_updates\Updater;
use Drupal\automatic_updates\Validation\ValidationResult;
use Drupal\Core\Extension\ExtensionVersion;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates that core updates are within a supported version range.
 */
class UpdateVersionValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The updater service.
   *
   * @var \Drupal\automatic_updates\Updater
   */
  protected $updater;

  /**
   * Constructs an UpdateVersionSubscriber.
   *
   * @param \Drupal\automatic_updates\Updater $updater
   *   The updater service.
   */
  public function __construct(Updater $updater) {
    $this->updater = $updater;
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
    $from_version = ExtensionVersion::createFromVersionString($this->getCoreVersion());
    $core_package_name = $this->updater->getCorePackageName();
    $to_version = ExtensionVersion::createFromVersionString($event->getPackageVersions()[$core_package_name]);

    if ($from_version->getMajorVersion() !== $to_version->getMajorVersion()) {
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
