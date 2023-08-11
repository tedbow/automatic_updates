<?php

declare(strict_types = 1);

namespace Drupal\automatic_updates;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\CronInterface;
use Drupal\Core\Utility\Error;
use Drupal\package_manager\PathLocator;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\NullLogger;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs updates as a detached background process after regular cron tasks.
 *
 * The update process will be started in a detached process which will continue
 * running after the web request has terminated. This is done after the
 * decorated cron service has been called, so regular cron tasks will always be
 * run regardless of whether there is an update available and whether an update
 * is successful.
 *
 * @todo Rename this class to CronUpdateRunner because it is no longer a stage
 *   in https://drupal.org/i/3375940.
 *
 * @internal
 *   This class implements logic specific to Automatic Updates' cron hook
 *   implementation and may be changed or removed at any time without warning.
 *   It should not be called directly, and external code should not interact
 *   with it.
 */
class CronUpdateStage implements CronInterface, LoggerAwareInterface {

  use LoggerAwareTrait;

  /**
   * The current interface between PHP and the server.
   *
   * @var string
   */
  private static $serverApi = PHP_SAPI;

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
   * Constructs a CronUpdateStage object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   * @param \Drupal\package_manager\PathLocator $pathLocator
   *   The path locator service.
   * @param \Drupal\Core\CronInterface $inner
   *   The decorated cron service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly PathLocator $pathLocator,
    private readonly CronInterface $inner,
    private readonly TimeInterface $time,
  ) {
    $this->setLogger(new NullLogger());
  }

  /**
   * Runs the terminal update command.
   */
  protected function runTerminalUpdateCommand(): void {
    $command_path = $this->getCommandPath();
    $php_binary_finder = new PhpExecutableFinder();

    // Use the `&` on the command line to detach this process after it is
    // started. This will allow the command to outlive the web request.
    $process = Process::fromShellCommandline($php_binary_finder->find() . " $command_path auto-update --is-from-web &")
      ->setWorkingDirectory($this->pathLocator->getProjectRoot())
      ->setTimeout(0);

    try {
      $process->start();
      // Wait for the process to have an ID, otherwise the web request may end
      // before the detached process has a chance to start.
      $wait_until = $this->time->getCurrentTime() + 5;
      do {
        sleep(1);
        $pid = $process->getPid();
        if ($pid) {
          break;
        }
      } while ($wait_until > $this->time->getCurrentTime());
    }
    catch (\Throwable $throwable) {
      // @todo Just call Error::logException() in https://drupal.org/i/3377458.
      if (method_exists(Error::class, 'logException')) {
        Error::logException($this->logger, $throwable, 'Unable to start background update.');
      }
      else {
        watchdog_exception('automatic_updates', $throwable, 'Unable to start background update.');
      }
    }

    if ($process->isTerminated()) {
      if ($process->getExitCode() !== 0) {
        $this->logger->error('Background update failed: %message', [
          '%message' => $process->getErrorOutput(),
        ]);
      }
    }
    elseif (empty($pid)) {
      $this->logger->error('Background update failed because the process did not start within 5 seconds.');
    }
  }

  /**
   * Indicates if we are currently running at the command line.
   *
   * @return bool
   *   TRUE if we are running at the command line, otherwise FALSE.
   */
  final public static function isCommandLine(): bool {
    return self::$serverApi === 'cli';
  }

  /**
   * {@inheritdoc}
   */
  public function run() {
    // Always run the cron service before we trigger the update terminal
    // command.
    $decorated_cron_succeeded = $this->inner->run();

    $method = $this->configFactory->get('automatic_updates.settings')
      ->get('unattended.method');
    // If we are configured to run updates via the web, and we're actually being
    // accessed via the web (i.e., anything that isn't the command line), go
    // ahead and try to do the update.
    if ($method === 'web' && !self::isCommandLine()) {
      $this->runTerminalUpdateCommand();
    }
    return $decorated_cron_succeeded;
  }

  /**
   * Gets the cron update mode.
   *
   * @return string
   *   The cron update mode. Will be one of the following constants:
   *   - self::DISABLED if updates during
   *     cron are entirely disabled.
   *   - self::SECURITY only security
   *     updates can be done during cron.
   *   - self::ALL if all updates are
   *     allowed during cron.
   */
  final public function getMode(): string {
    $mode = $this->configFactory->get('automatic_updates.settings')->get('unattended.level');
    return $mode ?: static::SECURITY;
  }

  /**
   * Gets the command path.
   *
   * @return string
   *   The command path.
   *
   * @throws \Exception
   *   Thrown if command path does not exist.
   *
   * @todo Remove in https://drupal.org/i/3360485.
   */
  public function getCommandPath(): string {
    // For some reason 'vendor/bin/drush' does not exist in build tests but this
    // method will be removed entirely before beta.
    $command_path = $this->pathLocator->getVendorDirectory() . '/drush/drush/drush';
    if (!is_executable($command_path)) {
      throw new \Exception("The Automatic Updates terminal command is not available at $command_path.");
    }
    return $command_path;
  }

}
