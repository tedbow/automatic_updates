<?php

namespace Drupal\automatic_updates;

use Drupal\automatic_updates\Exception\UpdateException;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * A batch processor for updates.
 */
class BatchProcessor {

  /**
   * Gets the updater service.
   *
   * @return \Drupal\automatic_updates\Updater
   *   The updater service.
   */
  protected static function getUpdater(): Updater {
    return \Drupal::service('automatic_updates.updater');
  }

  /**
   * Records messages from a throwable, then re-throws it.
   *
   * @param \Throwable $error
   *   The caught exception.
   * @param array $context
   *   The current context of the batch job.
   *
   * @throws \Throwable
   *   The caught exception, which will always be re-thrown once its messages
   *   have been recorded.
   */
  protected static function handleException(\Throwable $error, array &$context): void {
    $error_messages = [
      $error->getMessage(),
    ];

    if ($error instanceof UpdateException) {
      foreach ($error->getValidationResults() as $result) {
        $messages = $result->getMessages();
        if (count($messages) > 1) {
          array_unshift($messages, $result->getSummary());
        }
        $error_messages = array_merge($error_messages, $messages);
      }
    }

    foreach ($error_messages as $error_message) {
      $context['results']['errors'][] = $error_message;
    }
    throw $error;
  }

  /**
   * Calls the updater's begin() method.
   *
   * @param array $context
   *   The current context of the batch job.
   *
   * @see \Drupal\automatic_updates\Updater::begin()
   */
  public static function begin(array &$context): void {
    try {
      static::getUpdater()->begin();
    }
    catch (\Throwable $e) {
      static::handleException($e, $context);
    }
  }

  /**
   * Calls the updater's stageVersions() method.
   *
   * @param string[] $project_versions
   *   The project versions to be staged in the update, keyed by package name.
   * @param array $context
   *   The current context of the batch job.
   *
   * @see \Drupal\automatic_updates\Updater::stageVersions()
   */
  public static function stageProjectVersions(array $project_versions, array &$context): void {
    try {
      static::getUpdater()->stageVersions($project_versions);
    }
    catch (\Throwable $e) {
      static::handleException($e, $context);
    }
  }

  /**
   * Finishes the batch job.
   *
   * @param bool $success
   *   Indicate that the batch API tasks were all completed successfully.
   * @param array $results
   *   An array of all the results.
   * @param array $operations
   *   A list of the operations that had not been completed by the batch API.
   */
  public static function finish(bool $success, array $results, array $operations): ?RedirectResponse {
    if ($success) {
      return new RedirectResponse(Url::fromRoute('automatic_updates.confirmation_page', [], ['absolute' => TRUE])->toString());
    }
    if (isset($results['errors'])) {
      foreach ($results['errors'] as $error) {
        \Drupal::messenger()->addError($error);
      }
    }
    else {
      \Drupal::messenger()->addError("Update error");
    }
    return NULL;
  }

}
