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
   * Whether or not cron updates are hard-disabled.
   *
   * @var bool
   *
   * @todo Remove this when TUF integration is stable.
   */
  private static $disabled = TRUE;

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
    if ($this->getMode() === static::DISABLED) {
      return;
    }

    $next_release = $this->releaseChooser->getLatestInInstalledMinor();
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
    $installed_version = (new ProjectInfo('drupal'))->getInstalledVersion();
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
      $this->logger->error($e->getMessage());
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
      $this->logger->error($e->getMessage());
    }
  }

  /**
   * Gets the cron update mode.
   *
   * @return string
   *   The cron update mode. Will be one of the following constants:
   *   - \Drupal\automatic_updates\CronUpdater::DISABLED if updates during cron
   *     are entirely disabled.
   *   - \Drupal\automatic_updates\CronUpdater::SECURITY only security updates
   *     can be done during cron.
   *   - \Drupal\automatic_updates\CronUpdater::ALL if all updates are allowed
   *     during cron.
   *
   * @todo Make this always return a string, with a sensible default, in
   *   https://www.drupal.org/i/3276534.
   */
  final public function getMode(): ?string {
    if (self::$disabled) {
      return static::DISABLED;
    }
    return $this->configFactory->get('automatic_updates.settings')->get('cron');
  }

}
