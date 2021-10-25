<?php

namespace Drupal\automatic_updates\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\package_manager\ComposerUtility;
use Drupal\package_manager\ValidationResult;

/**
 * Event fired when a site is updating.
 *
 * These events allow listeners to validate updates at various points in the
 * update process.  Listeners to these events should add validation results via
 * ::addValidationResult() if necessary. Only error level validation results
 * will stop an update from continuing.
 */
abstract class UpdateEvent extends Event {

  /**
   * The validation results.
   *
   * @var \Drupal\package_manager\ValidationResult[]
   */
  protected $results = [];

  /**
   * The Composer utility object for the active directory.
   *
   * @var \Drupal\package_manager\ComposerUtility
   */
  protected $activeComposer;

  /**
   * Constructs a new UpdateEvent object.
   *
   * @param \Drupal\package_manager\ComposerUtility $active_composer
   *   A Composer utility object for the active directory.
   */
  public function __construct(ComposerUtility $active_composer) {
    $this->activeComposer = $active_composer;
  }

  /**
   * Returns a Composer utility object for the active directory.
   *
   * @return \Drupal\package_manager\ComposerUtility
   *   The Composer utility object for the active directory.
   */
  public function getActiveComposer(): ComposerUtility {
    return $this->activeComposer;
  }

  /**
   * Adds a validation result.
   *
   * @param \Drupal\package_manager\ValidationResult $validation_result
   *   The validation result.
   */
  public function addValidationResult(ValidationResult $validation_result): void {
    $this->results[] = $validation_result;
  }

  /**
   * Gets the validation results.
   *
   * @param int|null $severity
   *   (optional) The severity for the results to return. Should be one of the
   *   SystemManager::REQUIREMENT_* constants.
   *
   * @return \Drupal\package_manager\ValidationResult[]
   *   The validation results.
   */
  public function getResults(?int $severity = NULL): array {
    if ($severity !== NULL) {
      return array_filter($this->results, function ($result) use ($severity) {
        return $result->getSeverity() === $severity;
      });
    }
    return $this->results;
  }

}
