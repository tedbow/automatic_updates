<?php

namespace Drupal\package_manager\Event;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines an interface for events which can collect validation warnings.
 */
interface WarningEventInterface {

  /**
   * Adds a warning.
   *
   * @param array $messages
   *   The warning messages.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $summary
   *   (optional) The summary.
   */
  public function addWarning(array $messages, TranslatableMarkup $summary = NULL);

}
