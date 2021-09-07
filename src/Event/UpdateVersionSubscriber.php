<?php

namespace Drupal\automatic_updates\Event;

use Drupal\automatic_updates\AutomaticUpdatesEvents;
use Drupal\automatic_updates\Validation\ValidationResult;
use Drupal\Core\Extension\ExtensionVersion;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates that core updates are within a supported version range.
 */
class UpdateVersionSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Constructs an UpdateVersionSubscriber.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   */
  public function __construct(ModuleHandlerInterface $module_handler) {
    // Load procedural functions needed for ::getCoreVersion().
    $module_handler->loadInclude('update', 'inc', 'update.compare');
  }

  /**
   * Returns the running core version, according to the Update module.
   *
   * @return string
   *   The running core version as known to the Update module.
   */
  protected function getCoreVersion(): string {
    $available_updates = update_calculate_project_data(update_get_available());
    return $available_updates['drupal']['existing_version'];
  }

  /**
   * Validates that core is not being updated to another minor or major version.
   *
   * @param \Drupal\automatic_updates\Event\PreStartEvent $event
   *   The event object.
   */
  public function checkUpdateVersion(PreStartEvent $event): void {
    $from_version = ExtensionVersion::createFromVersionString($this->getCoreVersion());
    $to_version = ExtensionVersion::createFromVersionString($event->getPackageVersions()['drupal/core']);

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
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      AutomaticUpdatesEvents::PRE_START => 'checkUpdateVersion',
    ];
  }

}
