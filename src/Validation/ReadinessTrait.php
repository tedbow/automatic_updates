<?php

namespace Drupal\automatic_updates\Validation;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\package_manager\ValidationResult;
use Drupal\system\SystemManager;

/**
 * Common methods for working with readiness checkers.
 *
 * @internal
 *   This trait implements logic to output the messages from readiness checkers
 *   on admin pages. It may be changed or removed at any time without warning
 *   and should not be used by external code.
 */
trait ReadinessTrait {

  /**
   * Gets a message, based on severity, when readiness checkers fail.
   *
   * @param int $severity
   *   The severity. Should be one of the SystemManager::REQUIREMENT_*
   *   constants.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The message.
   *
   * @see \Drupal\system\SystemManager::REQUIREMENT_ERROR
   * @see \Drupal\system\SystemManager::REQUIREMENT_WARNING
   */
  protected function getFailureMessageForSeverity(int $severity): TranslatableMarkup {
    return $severity === SystemManager::REQUIREMENT_WARNING ?
      // @todo Link "automatic updates" to documentation in
      //   https://www.drupal.org/node/3168405.
      $this->t('Your site does not pass some readiness checks for automatic updates. Depending on the nature of the failures, it might affect the eligibility for automatic updates.') :
      $this->t('Your site does not pass some readiness checks for automatic updates. It cannot be automatically updated until further action is performed.');
  }

  /**
   * Returns the overall severity for a set of validation results.
   *
   * @param \Drupal\package_manager\ValidationResult[] $results
   *   The validation results.
   *
   * @return int
   *   The overall severity of the results. Will be be one of the
   *   SystemManager::REQUIREMENT_* constants.
   */
  protected function getOverallSeverity(array $results): int {
    foreach ($results as $result) {
      if ($result->getSeverity() === SystemManager::REQUIREMENT_ERROR) {
        return SystemManager::REQUIREMENT_ERROR;
      }
    }
    // If there were no errors, then any remaining results must be warnings.
    return $results ? SystemManager::REQUIREMENT_WARNING : SystemManager::REQUIREMENT_OK;
  }

  /**
   * Adds a set of validation results to the messages.
   *
   * @param \Drupal\package_manager\ValidationResult[] $results
   *   The validation results.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   */
  protected function displayResults(array $results, MessengerInterface $messenger, RendererInterface $renderer): void {
    $severity = $this->getOverallSeverity($results);

    if ($severity === SystemManager::REQUIREMENT_OK) {
      return;
    }

    // Format the results as a single item list prefixed by a preamble message.
    $build = [
      '#theme' => 'item_list__automatic_updates_validation_results',
      '#prefix' => $this->getFailureMessageForSeverity($severity),
      '#items' => array_map([$this, 'formatResult'], $results),
    ];
    $message = $renderer->renderRoot($build);

    if ($severity === SystemManager::REQUIREMENT_ERROR) {
      $messenger->addError($message);
    }
    else {
      $messenger->addWarning($message);
    }
  }

  /**
   * Formats a single validation result as an item in an item list.
   *
   * @param \Drupal\package_manager\ValidationResult $result
   *   A validation result.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|array
   *   The validation result, formatted for inclusion in a themed item list as
   *   either a translated string, or a renderable array.
   */
  protected function formatResult(ValidationResult $result) {
    $messages = $result->getMessages();
    return count($messages) === 1 ? reset($messages) : $result->getSummary();
  }

}
