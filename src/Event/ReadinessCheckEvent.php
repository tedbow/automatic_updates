<?php

namespace Drupal\automatic_updates\Event;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\package_manager\Event\PreOperationStageEvent;
use Drupal\package_manager\Stage;
use Drupal\package_manager\ValidationResult;

/**
 * Event fired when checking if the site could perform an update.
 *
 * An update is not actually being started when this event is being fired. It
 * should be used to notify site admins if the site is in a state which will
 * not allow automatic updates to succeed.
 *
 * This event should only be dispatched from ReadinessValidationManager to
 * allow caching of the results.
 *
 * @see \Drupal\automatic_updates\Validation\ReadinessValidationManager
 */
class ReadinessCheckEvent extends PreOperationStageEvent {

  /**
   * The desired package versions to update to, keyed by package name.
   *
   * @var string[]
   */
  protected $packageVersions;

  /**
   * Constructs a ReadinessCheckEvent object.
   *
   * @param \Drupal\package_manager\Stage $stage
   *   The stage service.
   * @param string[] $project_versions
   *   (optional) The versions of the packages to update to, keyed by package
   *   name.
   */
  public function __construct(Stage $stage, array $project_versions = []) {
    parent::__construct($stage);
    if ($project_versions) {
      if (count($project_versions) !== 1 || !array_key_exists('drupal', $project_versions)) {
        throw new \InvalidArgumentException("Currently only updates to Drupal core are supported.");
      }
      $core_packages = array_keys($this->getStage()->getActiveComposer()->getCorePackages());
      // Update all core packages to the same version.
      $package_versions = array_fill(0, count($core_packages), $project_versions['drupal']);
      $this->packageVersions = array_combine($core_packages, $package_versions);
    }
    else {
      $this->packageVersions = [];
    }
  }

  /**
   * Returns the desired package versions to update to.
   *
   * @return string[]
   *   The desired package versions to update to, keyed by package name.
   */
  public function getPackageVersions(): array {
    return $this->packageVersions;
  }

  /**
   * Adds warning information to the event.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup[] $messages
   *   The warning messages.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $summary
   *   (optional) The summary of warning messages. Required if there is more
   *   than one message.
   */
  public function addWarning(array $messages, ?TranslatableMarkup $summary = NULL): void {
    $this->results[] = ValidationResult::createWarning($messages, $summary);
  }

}
