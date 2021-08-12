<?php

namespace Drupal\automatic_updates\Event;

use Drupal\automatic_updates\Validation\ValidationResult;
use Drupal\Component\EventDispatcher\Event;

/**
 * Event fired when a site is updating.
 *
 * Subscribers to this event should call ::addValidationResult().
 *
 * @see \Drupal\automatic_updates\AutomaticUpdatesEvents
 */
class UpdateEvent extends Event {

  /**
   * The validation results.
   *
   * @var \Drupal\automatic_updates\Validation\ValidationResult[]
   */
  protected $results = [];

  /**
   * Adds a validation result.
   *
   * @param \Drupal\automatic_updates\Validation\ValidationResult $validation_result
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
   * @return \Drupal\automatic_updates\Validation\ValidationResult[]
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
