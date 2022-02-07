<?php

namespace Drupal\automatic_updates\Validation;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\system\SystemManager;

/**
 * Common methods for working with readiness checkers.
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
   */
  protected function displayResults(array $results, MessengerInterface $messenger): void {
    $severity = $this->getOverallSeverity($results);

    if ($severity === SystemManager::REQUIREMENT_OK) {
      return;
    }

    $message = $this->getFailureMessageForSeverity($severity);
    if ($severity === SystemManager::REQUIREMENT_ERROR) {
      $messenger->addError($message);
    }
    else {
      $messenger->addWarning($message);
    }

    foreach ($results as $result) {
      $messages = $result->getMessages();
      $message = count($messages) === 1 ? $messages[0] : $result->getSummary();

      if ($result->getSeverity() === SystemManager::REQUIREMENT_ERROR) {
        $messenger->addError($message);
      }
      else {
        $messenger->addWarning($message);
      }
    }
  }

}
