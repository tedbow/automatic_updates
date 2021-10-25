<?php

namespace Drupal\automatic_updates\Exception;

/**
 * Defines a custom exception for a failure during an update.
 */
class UpdateException extends \RuntimeException {

  /**
   * The validation results for the exception.
   *
   * @var \Drupal\package_manager\ValidationResult[]
   */
  protected $validationResults;

  /**
   * Constructs an UpdateException object.
   *
   * @param \Drupal\package_manager\ValidationResult[] $validation_results
   *   The validation results.
   * @param string $message
   *   The exception message.
   */
  public function __construct(array $validation_results, string $message) {
    parent::__construct($message);
    $this->validationResults = $validation_results;
  }

  /**
   * Gets the validation results for the exception.
   *
   * @return \Drupal\package_manager\ValidationResult[]
   *   The validation results.
   */
  public function getValidationResults(): array {
    return $this->validationResults;
  }

}
