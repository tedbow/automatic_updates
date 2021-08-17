<?php

namespace Drupal\automatic_updates\Validation;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\system\SystemManager;

/**
 * A value object to contain the results of a validation.
 */
class ValidationResult {

  /**
   * A succinct summary of the results.
   *
   * @var \Drupal\Core\StringTranslation\TranslatableMarkup
   */
  protected $summary;

  /**
   * The error messages.
   *
   * @var \Drupal\Core\StringTranslation\TranslatableMarkup[]
   */
  protected $messages;

  /**
   * The severity of the result.
   *
   * @var int
   */
  protected $severity;

  /**
   * Creates a ValidationResult object.
   *
   * @param int $severity
   *   The severity of the result. Should be one of the
   *   SystemManager::REQUIREMENT_* constants.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup[] $messages
   *   The error messages.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $summary
   *   The errors summary.
   */
  private function __construct(int $severity, array $messages, ?TranslatableMarkup $summary = NULL) {
    if (count($messages) > 1 && !$summary) {
      throw new \InvalidArgumentException('If more than one message is provided, a summary is required.');
    }
    $this->summary = $summary;
    $this->messages = $messages;
    $this->severity = $severity;
  }

  /**
   * Creates an error ValidationResult object.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup[] $messages
   *   The error messages.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $summary
   *   The errors summary.
   *
   * @return static
   */
  public static function createError(array $messages, ?TranslatableMarkup $summary = NULL): self {
    return new static(SystemManager::REQUIREMENT_ERROR, $messages, $summary);
  }

  /**
   * Creates a warning ValidationResult object.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup[] $messages
   *   The error messages.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $summary
   *   The errors summary.
   *
   * @return static
   */
  public static function createWarning(array $messages, ?TranslatableMarkup $summary = NULL): self {
    return new static(SystemManager::REQUIREMENT_WARNING, $messages, $summary);
  }

  /**
   * Gets the summary.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|null
   *   The summary.
   */
  public function getSummary(): ?TranslatableMarkup {
    return $this->summary;
  }

  /**
   * Gets the messages.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   The error or warning messages.
   */
  public function getMessages(): array {
    return $this->messages;
  }

  /**
   * The severity of the result.
   *
   * @return int
   *   Either SystemManager::REQUIREMENT_ERROR or
   *   SystemManager::REQUIREMENT_WARNING.
   */
  public function getSeverity(): int {
    return $this->severity;
  }

}