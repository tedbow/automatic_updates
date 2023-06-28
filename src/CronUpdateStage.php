<?php

declare(strict_types = 1);

namespace Drupal\automatic_updates;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\CronInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\package_manager\ComposerInspector;
use Drupal\package_manager\FailureMarker;
use Drupal\package_manager\PathLocator;
use PhpTuf\ComposerStager\Domain\Core\Beginner\BeginnerInterface;
use PhpTuf\ComposerStager\Domain\Core\Committer\CommitterInterface;
use PhpTuf\ComposerStager\Domain\Core\Stager\StagerInterface;
use PhpTuf\ComposerStager\Infrastructure\Factory\Path\PathFactoryInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Defines a service that updates via cron.
 *
 * @internal
 *   This class implements logic specific to Automatic Updates' cron hook
 *   implementation and may be changed or removed at any time without warning.
 *   It should not be called directly, and external code should not interact
 *   with it.
 */
class CronUpdateStage extends UnattendedUpdateStageBase implements CronInterface {

  /**
   * Constructs a CronUpdateStage object.
   *
   * @param \Drupal\automatic_updates\ReleaseChooser $releaseChooser
   *   The cron release chooser service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   * @param \Drupal\package_manager\ComposerInspector $composerInspector
   *   The Composer inspector service.
   * @param \Drupal\package_manager\PathLocator $pathLocator
   *   The path locator service.
   * @param \PhpTuf\ComposerStager\Domain\Core\Beginner\BeginnerInterface $beginner
   *   The beginner service.
   * @param \PhpTuf\ComposerStager\Domain\Core\Stager\StagerInterface $stager
   *   The stager service.
   * @param \PhpTuf\ComposerStager\Domain\Core\Committer\CommitterInterface $committer
   *   The committer service.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher service.
   * @param \Drupal\Core\TempStore\SharedTempStoreFactory $tempStoreFactory
   *   The shared tempstore factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \PhpTuf\ComposerStager\Infrastructure\Factory\Path\PathFactoryInterface $pathFactory
   *   The path factory service.
   * @param \Drupal\package_manager\FailureMarker $failureMarker
   *   The failure marker service.
   * @param \Drupal\Core\CronInterface $inner
   *   The decorated cron service.
   */
  public function __construct(
    ReleaseChooser $releaseChooser,
    ConfigFactoryInterface $configFactory,
    ComposerInspector $composerInspector,
    PathLocator $pathLocator,
    BeginnerInterface $beginner,
    StagerInterface $stager,
    CommitterInterface $committer,
    FileSystemInterface $fileSystem,
    EventDispatcherInterface $eventDispatcher,
    SharedTempStoreFactory $tempStoreFactory,
    TimeInterface $time,
    PathFactoryInterface $pathFactory,
    FailureMarker $failureMarker,
    private readonly CronInterface $inner
  ) {
    parent::__construct($releaseChooser, $configFactory, $composerInspector, $pathLocator, $beginner, $stager, $committer, $fileSystem, $eventDispatcher, $tempStoreFactory, $time, $pathFactory, $failureMarker);
  }

  /**
   * Runs the terminal update command.
   */
  public function runTerminalUpdateCommand(): void {
    // @todo Make a validator to ensure this path exists if settings select
    //   background updates.
    // @todo Replace drush call with Symfony console command in
    //   https://www.drupal.org/i/3360485
    // @todo Why isn't it in vendor bin?
    $drush_path = $this->pathLocator->getVendorDirectory() . '/drush/drush/drush';
    $phpBinaryFinder = new PhpExecutableFinder();
    // Test generic drush output
    $drush_check = Process::fromShellCommandline($phpBinaryFinder->find() . " $drush_path");
    $cwd = $this->pathLocator->getProjectRoot();

    if ($web_root = $this->pathLocator->getWebRoot()) {
      $cwd .= DIRECTORY_SEPARATOR . $web_root;
    }

    $cwd .= DIRECTORY_SEPARATOR . 'sites/default';
    $drush_check->setWorkingDirectory($cwd);

    try {
      $drush_check->mustRun();
    }
    catch (\Throwable $throwable) {
    }

    $process = Process::fromShellCommandline($phpBinaryFinder->find() . " $drush_path auto-update &");
    // Temporary command to test detached process still runs after response.
    // $process = Process::fromShellCommandline($phpBinaryFinder->find() . " $drush_path test-process &");
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
  final public function begin(array $project_versions, ?int $timeout = 300): never {
    // Unattended updates should never be started using this method. They should
    // only be done by ::handleCron(), which has a strong opinion about which
    // release to update to. Throwing an exception here is just to enforce this
    // boundary. To update to a specific version of core, use
    // \Drupal\automatic_updates\UpdateStage::begin() (which is called in
    // ::performUpdate() to start the update to the target version of core
    // chosen by ::handleCron()).
    throw new \BadMethodCallException(__METHOD__ . '() cannot be called directly.');
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
      else {
      }
    }
    return $inner_success;
  }

}
