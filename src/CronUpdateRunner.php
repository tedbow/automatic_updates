<?php

declare(strict_types = 1);

namespace Drupal\automatic_updates;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\CronInterface;
use Drupal\package_manager\PathLocator;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Defines a service that updates via cron.
 *
 * @internal
 *   This class implements logic specific to Automatic Updates' cron hook
 *   implementation and may be changed or removed at any time without warning.
 *   It should not be called directly, and external code should not interact
 *   with it.
 */
class CronUpdateRunner implements CronInterface {

  use LoggerAwareTrait;

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
   * Constructs a CronUpdateRunner object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   * @param \Drupal\package_manager\PathLocator $pathLocator
   *   The path locator service.
   * @param \Drupal\Core\CronInterface $inner
   *   The decorated cron service.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly PathLocator $pathLocator,
    private readonly CronInterface $inner
  ) {}

  /**
   * Runs the terminal update command.
   */
  public function runTerminalUpdateCommand(): void {
    // @todo Make a validator to ensure this path exists if settings select
    //   background updates.
    // @todo Replace drush call with Symfony console command in
    //   https://www.drupal.org/i/3360485
    // @todo Why isn't it in vendor bin in build tests?
    $drush_path = $this->pathLocator->getVendorDirectory() . '/drush/drush/drush';
    $phpBinaryFinder = new PhpExecutableFinder();

    $process = Process::fromShellCommandline($phpBinaryFinder->find() . " $drush_path auto-update &");
    $process->setWorkingDirectory($this->pathLocator->getProjectRoot() . DIRECTORY_SEPARATOR . $this->pathLocator->getWebRoot());
    // $process->disableOutput();
    $process->setTimeout(0);

    try {
      $process->start();
      sleep(1);
      $wait_till = time() + 5;
      // Wait for the process to start.
      while (is_null($process->getPid()) && $wait_till > time()) {
      }
    }

    catch (\Throwable $throwable) {
      watchdog_exception('automatic_updates', $throwable, 'Unable to start background update.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function run() {
    $method = $this->configFactory->get('automatic_updates.settings')
      ->get('unattended.method');
    // Always run the cron service before we trigger the update terminal
    // command.
    $inner_success = $this->inner->run();

    // If we are configured to run updates via the web, and we're actually being
    // accessed via the web (i.e., anything that isn't the command line), go
    // ahead and try to do the update. In all other circumstances, just run the
    // normal cron handler.
    if ($this->getMode() !== self::DISABLED && $method === 'web') {
      $lock = \Drupal::lock();
      if ($lock->acquire('cron', 30)) {
        $this->runTerminalUpdateCommand();
        $lock->release('cron');
      }
    }
    return $inner_success;
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

}
