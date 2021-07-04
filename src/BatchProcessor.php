<?php

namespace Drupal\automatic_updates;

use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

class BatchProcessor {

  protected static function getUpdater(): Updater {
    return \Drupal::service('automatic_updates.updater');
  }

  public static function begin() {
    static::getUpdater()->begin();
  }

  public static function stageProjectVersions($project_versions, &$context) {
    static::getUpdater()->stageVersions($project_versions);
  }
  /**
   * Finish batch.
   *
   * @param bool $success
   *   Indicate that the batch API tasks were all completed successfully.
   * @param array $results
   *   An array of all the results.
   * @param array $operations
   *   A list of the operations that had not been completed by the batch API.
   */
  public static function finish(bool $success, array $results, array $operations) {
    if ($success) {
      return new RedirectResponse(Url::fromRoute('update.confirmation_page', [], ['absolute' => TRUE])->toString());
    }
    \Drupal::messenger()->addError("Update error");
  }
}