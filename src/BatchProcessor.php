<?php

declare(strict_types = 1);

namespace Drupal\automatic_updates;

use Drupal\Core\Url;
use Drupal\system\Controller\DbUpdateController;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * A batch processor for updates.
 *
 * @internal
 *   This is an internal part of Automatic Updates and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
final class BatchProcessor {

  /**
   * The session key under which the stage ID is stored.
   *
   * @var string
   */
  public const STAGE_ID_SESSION_KEY = '_automatic_updates_stage_id';

  /**
   * The session key which indicates if the update is done in maintenance mode.
   *
   * @var string
   */
  public const MAINTENANCE_MODE_SESSION_KEY = '_automatic_updates_maintenance_mode';

  /**
   * The session key which stores error messages that occur in processing.
   *
   * @var string
   */
  private const ERROR_MESSAGES_SESSION_KEY = '_automatic_updates_errors';

  /**
   * Gets the update stage service.
   *
   * @return \Drupal\automatic_updates\UpdateStage
   *   The update stage service.
   */
  protected static function getStage(): UpdateStage {
    return \Drupal::service('automatic_updates.update_stage');
  }

  /**
   * Records messages from a throwable, then re-throws it.
   *
   * @param \Throwable $error
   *   The caught exception.
   *
   * @throws \Throwable
   *   The caught exception, which will always be re-thrown once its messages
   *   have been recorded.
   */
  protected static function handleException(\Throwable $error): never {
    // TRICKY: We need to store error messages in the session because the batch
    // context becomes a dangling reference when static variables are globally
    // reset by drupal_flush_all_caches(), which is called during the post-apply
    // phase of the update. Which means that, when ::postApply() is called, any
    // data added to the batch context in the current request is lost. On the
    // other hand, data stored in the session is not affected.
    /** @var \Symfony\Component\HttpFoundation\Session\SessionInterface $session */
    $session = \Drupal::service('session');
    $errors = $session->get(self::ERROR_MESSAGES_SESSION_KEY, []);
    $errors[] = $error->getMessage();
    $session->set(self::ERROR_MESSAGES_SESSION_KEY, $errors);

    throw $error;
  }

  /**
   * Calls the update stage's begin() method.
   *
   * @param string[] $project_versions
   *   The project versions to be staged in the update, keyed by package name.
   *
   * @see \Drupal\automatic_updates\UpdateStage::begin()
   */
  public static function begin(array $project_versions): void {
    try {
      $stage_id = static::getStage()->begin($project_versions);
      \Drupal::service('session')->set(static::STAGE_ID_SESSION_KEY, $stage_id);
    }
    catch (\Throwable $e) {
      static::handleException($e);
    }
  }

  /**
   * Calls the update stage's stage() method.
   *
   * @see \Drupal\automatic_updates\UpdateStage::stage()
   */
  public static function stage(): void {
    $stage_id = \Drupal::service('session')->get(static::STAGE_ID_SESSION_KEY);
    try {
      static::getStage()->claim($stage_id)->stage();
    }
    catch (\Throwable $e) {
      static::clean($stage_id);
      static::handleException($e);
    }
  }

  /**
   * Calls the update stage's apply() method.
   *
   * @param string $stage_id
   *   The stage ID.
   *
   * @see \Drupal\automatic_updates\UpdateStage::apply()
   */
  public static function commit(string $stage_id): void {
    try {
      static::getStage()->claim($stage_id)->apply();
      // The batch system does not allow any single request to run for longer
      // than a second, so this will force the next operation to be done in a
      // new request. This helps keep the running code in as consistent a state
      // as possible.
      // @see \Drupal\package_manager\Stage::apply()
      // @see \Drupal\package_manager\Stage::postApply()
      sleep(1);
    }
    catch (\Throwable $e) {
      static::handleException($e);
    }
  }

  /**
   * Calls the update stage's postApply() method.
   *
   * @param string $stage_id
   *   The stage ID.
   *
   * @see \Drupal\automatic_updates\UpdateStage::postApply()
   */
  public static function postApply(string $stage_id): void {
    try {
      static::getStage()->claim($stage_id)->postApply();
    }
    catch (\Throwable $e) {
      static::handleException($e);
    }
  }

  /**
   * Calls the update stage's destroy() method.
   *
   * @param string $stage_id
   *   The stage ID.
   *
   * @see \Drupal\automatic_updates\UpdateStage::destroy()
   */
  public static function clean(string $stage_id): void {
    try {
      static::getStage()->claim($stage_id)->destroy();
    }
    catch (\Throwable $e) {
      static::handleException($e);
    }
  }

  /**
   * Finishes the stage batch job.
   *
   * @param bool $success
   *   Indicate that the batch API tasks were all completed successfully.
   */
  public static function finishStage(bool $success): ?RedirectResponse {
    if ($success) {
      $stage_id = \Drupal::service('session')->get(static::STAGE_ID_SESSION_KEY);
      $url = Url::fromRoute('automatic_updates.confirmation_page', [
        'stage_id' => $stage_id,
      ]);
      return new RedirectResponse($url->setAbsolute()->toString());
    }
    static::handleBatchError();
    return NULL;
  }

  /**
   * Finishes the commit batch job.
   *
   * @param bool $success
   *   Indicate that the batch API tasks were all completed successfully.
   */
  public static function finishCommit(bool $success): ?RedirectResponse {
    \Drupal::service('session')->remove(static::STAGE_ID_SESSION_KEY);

    if ($success) {
      $url = Url::fromRoute('automatic_updates.finish')
        ->setAbsolute()
        ->toString();
      return new RedirectResponse($url);
    }
    static::handleBatchError();
    return NULL;
  }

  /**
   * Handles a batch job that finished with errors.
   */
  protected static function handleBatchError(): void {
    /** @var \Symfony\Component\HttpFoundation\Session\SessionInterface $session */
    $session = \Drupal::service('session');
    $errors = $session->get(self::ERROR_MESSAGES_SESSION_KEY);
    $session->remove(self::ERROR_MESSAGES_SESSION_KEY);

    if (is_array($errors)) {
      array_walk($errors, \Drupal::messenger()->addError(...));
    }
    else {
      \Drupal::messenger()->addError("Update error");
    }
  }

  /**
   * Reset maintenance mode after update.php.
   *
   * This wraps \Drupal\system\Controller\DbUpdateController::batchFinished()
   * because that function would leave the site in maintenance mode if we
   * redirected the user to update.php already in maintenance mode. We need to
   * take the site out of maintenance mode, if it was not enabled before they
   * submitted our confirmation form.
   *
   * @param bool $success
   *   Whether the batch API tasks were all completed successfully.
   * @param array $results
   *   An array of all the results.
   * @param array $operations
   *   A list of the operations that had not been completed by the batch API.
   *
   * @todo Remove the need for this workaround in
   *    https://www.drupal.org/i/3267817.
   *
   * @see \Drupal\update\Form\UpdateReady::submitForm()
   * @see automatic_updates_batch_alter()
   */
  public static function dbUpdateBatchFinished(bool $success, array $results, array $operations): void {
    DbUpdateController::batchFinished($success, $results, $operations);
    // Now that the update is done, we can put the site back online if it was
    // previously not in maintenance mode.
    // \Drupal\system\Controller\DbUpdateController::batchFinished() will not
    // unset maintenance mode if the site was in maintenance mode when the user
    // was redirected to update.php by
    // \Drupal\automatic_updates\Controller\UpdateController::onFinish().
    if (!\Drupal::request()->getSession()->remove(static::MAINTENANCE_MODE_SESSION_KEY)) {
      \Drupal::state()->set('system.maintenance_mode', FALSE);
    }
  }

}
