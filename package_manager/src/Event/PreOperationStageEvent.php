<?php

namespace Drupal\package_manager\Event;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\package_manager\ValidationResult;

/**
 * Base class for events dispatched before a stage life cycle operation.
 */
abstract class PreOperationStageEvent extends StageEvent {

  /**
   * The validation results.
   *
   * @var \Drupal\package_manager\ValidationResult[]
   */
  protected $results = [];

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
   * Adds error information to the event.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup[] $messages
   *   The error messages.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $summary
   *   (optional) The summary of error messages. Only required if there
   *   is more than one message.
   */
  public function addError(array $messages, ?TranslatableMarkup $summary = NULL): void {
    $this->results[] = ValidationResult::createError($messages, $summary);
  }

}
