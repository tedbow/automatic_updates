<?php

namespace Drupal\automatic_updates;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\package_manager\Exception\StageValidationException;

/**
 * Defines a service that updates via cron.
 *
 * @internal
 *   This class implements logic specific to Automatic Updates' cron hook
 *   implementation. It should not be called directly.
 */
class CronUpdater extends Updater {

  /**
   * All automatic updates are disabled.
   *
   * @var string
   */
  public const DISABLED = 'disable';

  /**
   * Only perform automatic security updates.
   *
   * @var string
   */
  public const SECURITY = 'security';

  /**
   * All automatic updates are enabled.
   *
   * @var string
   */
  public const ALL = 'patch';

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The cron release chooser service.
   *
   * @var \Drupal\automatic_updates\ReleaseChooser
   */
  protected $releaseChooser;

  /**
   * Constructs a CronUpdater object.
   *
   * @param \Drupal\automatic_updates\ReleaseChooser $release_chooser
   *   The cron release chooser service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   * @param mixed ...$arguments
   *   Additional arguments to pass to the parent constructor.
   */
  public function __construct(ReleaseChooser $release_chooser, LoggerChannelFactoryInterface $logger_factory, ...$arguments) {
    parent::__construct(...$arguments);
    $this->releaseChooser = $release_chooser;
    $this->logger = $logger_factory->get('automatic_updates');
  }

  /**
   * Handles updates during cron.
   */
  public function handleCron(): void {
    if ($this->isDisabled()) {
      return;
    }

    $next_release = $this->releaseChooser->refresh()->getLatestInInstalledMinor();
    if ($next_release) {
      $this->performUpdate($next_release->getVersion());
    }
  }

  /**
   * Performs the update.
   *
   * @param string $update_version
   *   The version to which to update.
   */
  private function performUpdate(string $update_version): void {
    $installed_version = (new ProjectInfo())->getInstalledVersion();
    if (empty($installed_version)) {
      $this->logger->error('Unable to determine the current version of Drupal core.');
      return;
    }

    // Do the bulk of the update in its own try-catch structure, so that we can
    // handle any exceptions or validation errors consistently, and destroy the
    // stage regardless of whether the update succeeds.
    try {
      $this->begin([
        'drupal' => $update_version,
      ]);
      $this->stage();
      $this->apply();

      $this->logger->info(
        'Drupal core has been updated from %previous_version to %update_version',
        [
          '%previous_version' => $installed_version,
          '%update_version' => $update_version,
        ]
      );
    }
    catch (\Throwable $e) {
      $this->handleException($e);
    }

    // If an error occurred during the pre-create event, the stage will be
    // marked as available and we shouldn't try to destroy it, since the stage
    // must be claimed in order to be destroyed.
    if ($this->isAvailable()) {
      return;
    }

    // If any pre-destroy event subscribers raise validation errors, ensure they
    // are formatted and logged. But if any pre- or post-destroy event
    // subscribers throw another exception, don't bother catching it, since it
    // will be caught and handled by the main cron service.
    try {
      $this->destroy();
    }
    catch (StageValidationException $e) {
      $this->handleException($e);
    }
  }

  /**
   * Determines if cron updates are disabled.
   *
   * @return bool
   *   TRUE if cron updates are disabled, otherwise FALSE.
   */
  private function isDisabled(): bool {
    return $this->configFactory->get('automatic_updates.settings')->get('cron') === static::DISABLED;
  }

  /**
   * Generates a log message from a stage validation exception.
   *
   * @param \Drupal\package_manager\Exception\StageValidationException $exception
   *   The validation exception.
   *
   * @return string
   *   The formatted log message, including all the validation results.
   */
  protected static function formatValidationException(StageValidationException $exception): string {
    $log_message = '';
    foreach ($exception->getResults() as $result) {
      $summary = $result->getSummary();
      if ($summary) {
        $log_message .= "<h3>$summary</h3><ul>";
        foreach ($result->getMessages() as $message) {
          $log_message .= "<li>$message</li>";
        }
        $log_message .= "</ul>";
      }
      else {
        $log_message .= ($log_message ? ' ' : '') . $result->getMessages()[0];
      }
    }
    return "<h2>{$exception->getMessage()}</h2>$log_message";
  }

  /**
   * Handles an exception that is caught during an update.
   *
   * @param \Throwable $e
   *   The caught exception.
   */
  protected function handleException(\Throwable $e): void {
    $message = $e instanceof StageValidationException
      ? static::formatValidationException($e)
      : $e->getMessage();
    $this->logger->error($message);
  }

}
