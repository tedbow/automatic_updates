<?php

namespace Drupal\package_manager\Event;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines an interface for events which can collect validation errors.
 */
interface ErrorEventInterface {

  /**
   * Adds a validation error.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup[] $messages
   *   The error messages.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $summary
   *   (optional) The summary.
   */
  public function addError(array $messages, TranslatableMarkup $summary = NULL);

}
