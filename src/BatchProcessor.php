<?php

namespace Drupal\automatic_updates;

use Drupal\Core\Url;
use Drupal\package_manager\Exception\StageValidationException;
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

    if ($error instanceof StageValidationException) {
      foreach ($error->getResults() as $result) {
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
   * @param string[] $project_versions
   *   The project versions to be staged in the update, keyed by package name.
   * @param array $context
   *   The current context of the batch job.
   *
   * @see \Drupal\automatic_updates\Updater::begin()
   */
  public static function begin(array $project_versions, array &$context): void {
    try {
      $stage_unique = static::getUpdater()->begin($project_versions);
      $context['results']['stage_id'] = $stage_unique;
    }
    catch (\Throwable $e) {
      static::handleException($e, $context);
    }
  }

  /**
   * Calls the updater's stageVersions() method.
   *
   * @param array $context
   *   The current context of the batch job.
   *
   * @see \Drupal\automatic_updates\Updater::stage()
   */
  public static function stage(array &$context): void {
    try {
      $stage_id = $context['results']['stage_id'];
      static::getUpdater()->claim($stage_id)->stage();
    }
    catch (\Throwable $e) {
      static::handleException($e, $context);
    }
  }

  /**
   * Calls the updater's commit() method.
   *
   * @param string $stage_id
   *   The stage ID.
   * @param array $context
   *   The current context of the batch job.
   *
   * @see \Drupal\automatic_updates\Updater::apply()
   */
  public static function commit(string $stage_id, array &$context): void {
    try {
      static::getUpdater()->claim($stage_id)->apply();
    }
    catch (\Throwable $e) {
      static::handleException($e, $context);
    }
  }

  /**
   * Calls the updater's clean() method.
   *
   * @param string $stage_id
   *   The stage ID.
   * @param array $context
   *   The current context of the batch job.
   *
   * @see \Drupal\automatic_updates\Updater::clean()
   */
  public static function clean(string $stage_id, array &$context): void {
    try {
      static::getUpdater()->claim($stage_id)->destroy();
    }
    catch (\Throwable $e) {
      static::handleException($e, $context);
    }
  }

  /**
   * Finishes the stage batch job.
   *
   * @param bool $success
   *   Indicate that the batch API tasks were all completed successfully.
   * @param array $results
   *   An array of all the results.
   * @param array $operations
   *   A list of the operations that had not been completed by the batch API.
   */
  public static function finishStage(bool $success, array $results, array $operations): ?RedirectResponse {
    if ($success) {
      $url = Url::fromRoute('automatic_updates.confirmation_page', [
        'stage_id' => $results['stage_id'],
      ]);
      return new RedirectResponse($url->setAbsolute()->toString());
    }
    static::handleBatchError($results);
    return NULL;
  }

  /**
   * Finishes the commit batch job.
   *
   * @param bool $success
   *   Indicate that the batch API tasks were all completed successfully.
   * @param array $results
   *   An array of all the results.
   * @param array $operations
   *   A list of the operations that had not been completed by the batch API.
   */
  public static function finishCommit(bool $success, array $results, array $operations): ?RedirectResponse {

    if ($success) {
      \Drupal::messenger()->addMessage('Update complete!');
      // @todo redirect to update.php?
      return new RedirectResponse(Url::fromRoute('update.status', [],
        ['absolute' => TRUE])->toString());
    }
    static::handleBatchError($results);
    return NULL;
  }

  /**
   * Handles a batch job that finished with errors.
   *
   * @param array $results
   *   The batch results.
   */
  protected static function handleBatchError(array $results): void {
    if (isset($results['errors'])) {
      foreach ($results['errors'] as $error) {
        \Drupal::messenger()->addError($error);
      }
    }
    else {
      \Drupal::messenger()->addError("Update error");
    }
  }

}
