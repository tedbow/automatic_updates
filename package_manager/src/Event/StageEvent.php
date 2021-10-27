<?php

namespace Drupal\package_manager\Event;

use Drupal\package_manager\Stage;
use Drupal\package_manager\ValidationResult;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Base class for all events related to the life cycle of the staging area.
 */
abstract class StageEvent extends Event {

  /**
   * The validation results.
   *
   * @var \Drupal\package_manager\ValidationResult[]
   */
  protected $results = [];

  /**
   * The stage which fired this event.
   *
   * @var \Drupal\package_manager\Stage
   */
  protected $stage;

  /**
   * Constructs a StageEvent object.
   *
   * @param \Drupal\package_manager\Stage $stage
   *   The stage which fired this event.
   */
  public function __construct(Stage $stage) {
    $this->stage = $stage;
  }

  /**
   * Returns the stage which fired this event.
   *
   * @return \Drupal\package_manager\Stage
   *   The stage which fired this event.
   */
  public function getStage(): Stage {
    return $this->stage;
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

  /**
   * Adds a validation result.
   *
   * @param \Drupal\package_manager\ValidationResult $validation_result
   *   The validation result.
   */
  public function addValidationResult(ValidationResult $validation_result): void {
    $this->results[] = $validation_result;
  }

}
