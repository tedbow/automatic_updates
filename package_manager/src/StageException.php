<?php

namespace Drupal\package_manager;

/**
 * Exception thrown when the staging area encounters an error condition.
 */
class StageException extends \RuntimeException {

  /**
   * Any relevant validation results.
   *
   * @var \Drupal\package_manager\ValidationResult[]
   */
  protected $results = [];

  /**
   * Constructs a StageException object.
   *
   * @param \Drupal\package_manager\ValidationResult[] $results
   *   Any relevant validation results.
   * @param mixed ...$arguments
   *   Arguments to pass to the parent constructor.
   */
  public function __construct(array $results = [], ...$arguments) {
    $this->results = $results;
    parent::__construct(...$arguments);
  }

  /**
   * Gets the validation results.
   *
   * @return \Drupal\package_manager\ValidationResult[]
   *   The validation results.
   */
  public function getResults(): array {
    return $this->results;
  }

}
