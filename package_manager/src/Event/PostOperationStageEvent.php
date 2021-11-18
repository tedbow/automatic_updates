<?php

namespace Drupal\package_manager\Event;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\package_manager\ValidationResult;

/**
 * Base class for events dispatched after a stage life cycle operation.
 */
abstract class PostOperationStageEvent extends StageEvent implements WarningEventInterface {

  /**
   * {@inheritdoc}
   */
  public function addWarning(array $messages, ?TranslatableMarkup $summary = NULL) {
    $this->results[] = ValidationResult::createWarning($messages, $summary);
  }

}
