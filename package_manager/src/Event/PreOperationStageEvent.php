<?php

namespace Drupal\package_manager\Event;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\package_manager\ValidationResult;

/**
 * Base class for events dispatched before a stage life cycle operation.
 */
abstract class PreOperationStageEvent extends StageEvent {

  /**
   * Adds error information to the event.
   */
  public function addError(array $messages, ?TranslatableMarkup $summary = NULL) {
    $this->results[] = ValidationResult::createError($messages, $summary);
  }

}
