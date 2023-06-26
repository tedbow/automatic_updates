<?php

declare(strict_types = 1);

namespace Drupal\automatic_updates;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\CronInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\Core\Utility\Error;
use Drupal\package_manager\ComposerInspector;
use Drupal\package_manager\Debugger;
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
    $process = Process::fromShellCommandline($phpBinaryFinder->find() . " $drush_path auto-update &");
    // Temporary command to test detached process still runs after response.
    // $process = Process::fromShellCommandline($phpBinaryFinder->find() . " $drush_path test-process &");
    $process->setworkingdirectory($this->pathLocator->getProjectRoot() . DIRECTORY_SEPARATOR . $this->pathLocator->getWebRoot());
    $process->disableOutput();
    $process->setTimeout(0);
    try {
      Debugger::debugOutput('before start:' . $process->getCommandLine());
      $process->start();
      sleep(1);
      $wait_till = time() + 5;
      // Wait for the process to start.
      while (is_null($process->getPid()) && $wait_till > time()) {
      }
      Debugger::debugOutput('after start');
    }
    catch (\Throwable $throwable) {
      watchdog_exception('automatic_updates', $throwable, 'affff');
      Debugger::debugOutput($process->getErrorOutput(), 'process error');
      Debugger::debugOutput($throwable, 'Could not perform background update.');
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
<<<<<<< HEAD
=======
   * Performs the update.
   *
   * @param string $target_version
   *   The target version of Drupal core.
   * @param int|null $timeout
   *   How long to allow the operation to run before timing out, in seconds, or
   *   NULL to never time out.
   *
   * @return bool
   *   Returns TRUE if any update was attempted, otherwise FALSE.
   */
  protected function performUpdate(string $target_version, ?int $timeout): bool {
    $project_info = new ProjectInfo('drupal');
    $update_started = FALSE;

    if (!$this->isAvailable()) {
      if ($project_info->isInstalledVersionSafe() && !$this->isApplying()) {
        $this->logger->notice('Cron will not perform any updates because there is an existing stage and the current version of the site is secure.');
        return $update_started;
      }
      if (!$project_info->isInstalledVersionSafe() && $this->isApplying()) {
        $this->logger->notice(
          'Cron will not perform any updates as an existing staged update is applying. The site is currently on an insecure version of Drupal core but will attempt to update to a secure version next time cron is run. This update may be applied manually at the <a href="%url">update form</a>.',
          ['%url' => Url::fromRoute('update.report_update')->setAbsolute()->toString()],
        );
        return $update_started;
      }
    }

    // Delete the existing staging area if not available and the site is
    // currently on an insecure version.
    if (!$project_info->isInstalledVersionSafe() && !$this->isAvailable() && !$this->isApplying()) {
      $destroy_message = $this->t('The existing stage was not in the process of being applied, so it was destroyed to allow updating the site to a secure version during cron.');
      $this->destroy(TRUE, $destroy_message);
      $this->logger->notice($destroy_message->getUntranslatedString());
    }

    $installed_version = $project_info->getInstalledVersion();
    if (empty($installed_version)) {
      $this->logger->error('Unable to determine the current version of Drupal core.');
      return $update_started;
    }

    // Do the bulk of the update in its own try-catch structure, so that we can
    // handle any exceptions or validation errors consistently, and destroy the
    // stage regardless of whether the update succeeds.
    try {
      $update_started = TRUE;
      // @see ::begin()
      $stage_id = parent::begin(['drupal' => $target_version], $timeout);
      $this->stage();
      $this->apply();
    }
    catch (\Throwable $e) {
      if ($e instanceof StageEventException && $e->event instanceof PreCreateEvent) {
        // If the error happened during PreCreateEvent then the update did not
        // really start.
        $update_started = FALSE;
      }
      // Send notifications about the failed update.
      $mail_params = [
        'previous_version' => $installed_version,
        'target_version' => $target_version,
        'error_message' => $e->getMessage(),
      ];
      // Omit the backtrace in e-mails. That will be visible on the site, and is
      // also stored in the failure marker.
      if ($e instanceof StageFailureMarkerException || $e instanceof ApplyFailedException) {
        $mail_params['error_message'] = $this->failureMarker->getMessage(FALSE);
      }
      if ($e instanceof ApplyFailedException) {
        $mail_params['urgent'] = TRUE;
        $key = 'cron_failed_apply';
      }
      elseif (!$project_info->isInstalledVersionSafe()) {
        $mail_params['urgent'] = TRUE;
        $key = 'cron_failed_insecure';
      }
      else {
        $mail_params['urgent'] = FALSE;
        $key = 'cron_failed';
      }

      foreach ($this->statusCheckMailer->getRecipients() as $email => $langcode) {
        $this->mailManager->mail('automatic_updates', $key, $email, $langcode, $mail_params);
      }
      $this->logger->error($e->getMessage());

      // If an error occurred during the pre-create event, the stage will be
      // marked as available and we shouldn't try to destroy it, since the stage
      // must be claimed in order to be destroyed.
      if (!$this->isAvailable()) {
        $this->destroy();
      }
      return $update_started;
    }
    $this->triggerPostApply($stage_id, $installed_version, $target_version);
    return TRUE;
  }

  /**
   * Triggers the post-apply tasks.
   *
   * @param string $stage_id
   *   The ID of the current stage.
   * @param string $start_version
   *   The version of Drupal core that started the update.
   * @param string $target_version
   *   The version of Drupal core to which we are updating.
   */
  protected function triggerPostApply(string $stage_id, string $start_version, string $target_version): void {
    // Perform a subrequest to run ::postApply(), which needs to be done in a
    // separate request.
    // @see parent::apply()
    $url = Url::fromRoute('automatic_updates.cron.post_apply', [
      'stage_id' => $stage_id,
      'installed_version' => $start_version,
      'target_version' => $target_version,
      'key' => $this->state->get('system.cron_key'),
    ]);
    $url = $url->setAbsolute()->toString();

    // If we're using a single-threaded web server (e.g., the built-in PHP web
    // server used in build tests), allow the post-apply request to be sent to
    // an alternate port.
    $port = $this->configFactory->get('automatic_updates.settings')
      ->get('cron_port');
    if ($port) {
      $url = (string) (new GuzzleUri($url))->withPort($port);
    }

    // Use the bare cURL API to make the request, so that we're not relying on
    // any third-party classes or other code which may have changed during the
    // update.
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
    $response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($status !== 200) {
      $this->logger->error('Post-apply tasks failed with output: %status %response', [
        '%status' => $status,
        '%response' => $response,
      ]);
    }
    curl_close($curl);
  }

  /**
   * Runs post-apply tasks.
   *
   * @param string $stage_id
   *   The stage ID.
   * @param string $installed_version
   *   The version of Drupal core that started the update.
   * @param string $target_version
   *   The version of Drupal core to which we updated.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   An empty 200 response if the post-apply tasks succeeded.
   */
  public function handlePostApply(string $stage_id, string $installed_version, string $target_version): Response {
    $owner = $this->tempStore->getMetadata(static::TEMPSTORE_LOCK_KEY)
      ->getOwnerId();
    // Reload the tempstore with the correct owner ID so we can claim the stage.
    $this->tempStore = $this->tempStoreFactory->get('package_manager_stage', $owner);

    $this->claim($stage_id);

    $this->logger->info(
      'Drupal core has been updated from %previous_version to %target_version',
      [
        '%previous_version' => $installed_version,
        '%target_version' => $target_version,
      ]
    );

    // Send notifications about the successful update.
    $mail_params = [
      'previous_version' => $installed_version,
      'updated_version' => $target_version,
    ];
    foreach ($this->statusCheckMailer->getRecipients() as $recipient => $langcode) {
      $this->mailManager->mail('automatic_updates', 'cron_successful', $recipient, $langcode, $mail_params);
    }

    // Run post-apply tasks in their own try-catch block so that, if anything
    // raises an exception, we'll log it and proceed to destroy the stage as
    // soon as possible (which is also what we do in ::performUpdate()).
    try {
      $this->postApply();
    }
    catch (\Throwable $e) {
      $this->logger->error($e->getMessage());
    }

    // If any pre-destroy event subscribers raise validation errors, ensure they
    // are formatted and logged. But if any pre- or post-destroy event
    // subscribers throw another exception, don't bother catching it, since it
    // will be caught and handled by the main cron service.
    try {
      $this->destroy();
    }
    catch (StageEventException $e) {
      $this->logger->error($e->getMessage());
    }

    return new Response();
  }

  /**
   * Gets the cron update mode.
   *
   * @return string
   *   The cron update mode. Will be one of the following constants:
   *   - \Drupal\automatic_updates\CronUpdateStage::DISABLED if updates during
   *     cron are entirely disabled.
   *   - \Drupal\automatic_updates\CronUpdateStage::SECURITY only security
   *     updates can be done during cron.
   *   - \Drupal\automatic_updates\CronUpdateStage::ALL if all updates are
   *     allowed during cron.
   */
  final public function getMode(): string {
    $mode = $this->configFactory->get('automatic_updates.settings')->get('unattended.level');
    return $mode ?: static::SECURITY;
  }

  /**
>>>>>>> 3.0.x
   * {@inheritdoc}
   */
  public function run() {
    $method = $this->configFactory->get('automatic_updates.settings')
      ->get('unattended.method');
    // Always run the cron service before we trigger the update terminal
    // command.
    $inner_success = $this->inner->run();
    Debugger::debugOutput('ran cron');

    // If we are configured to run updates via the web, and we're actually being
    // accessed via the web (i.e., anything that isn't the command line), go
    // ahead and try to do the update. In all other circumstances, just run the
    // normal cron handler.
    if ($this->getMode() !== self::DISABLED && $method === 'web') {
      Debugger::debugOutput('running terminal');
      $this->runTerminalUpdateCommand();
    }
    return $inner_success;
  }

}
