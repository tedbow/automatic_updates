<?php

declare(strict_types = 1);

namespace Drupal\automatic_updates;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\Core\Url;
use Drupal\package_manager\ComposerInspector;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Exception\ApplyFailedException;
use Drupal\package_manager\Exception\StageEventException;
use Drupal\package_manager\Exception\StageFailureMarkerException;
use Drupal\package_manager\FailureMarker;
use Drupal\package_manager\PathLocator;
use Drupal\package_manager\ProjectInfo;
use Drupal\update\ProjectRelease;
use Drush\Drush;
use PhpTuf\ComposerStager\API\Core\BeginnerInterface;
use PhpTuf\ComposerStager\API\Core\CommitterInterface;
use PhpTuf\ComposerStager\API\Core\StagerInterface;
use PhpTuf\ComposerStager\API\Path\Factory\PathFactoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * An updater that runs via a Drush command.
 *
 * @todo Make this class a generic console stage in https://drupal.org/i/3360485
 */
class DrushUpdateStage extends UpdateStage {

  /**
   * Constructs a DrushUpdateStage object.
   *
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock service.
   * @param \Drupal\automatic_updates\CronUpdateStage $cronUpdateRunner
   *   The cron update runner service.
   * @param \Drupal\Core\Mail\MailManagerInterface $mailManager
   *   The mail manager service.
   * @param \Drupal\automatic_updates\StatusCheckMailer $statusCheckMailer
   *   The status check mailer service.
   * @param \Drupal\automatic_updates\ReleaseChooser $releaseChooser
   *   The cron release chooser service.
   * @param \Drupal\package_manager\ComposerInspector $composerInspector
   *   The Composer inspector service.
   * @param \Drupal\package_manager\PathLocator $pathLocator
   *   The path locator service.
   * @param \PhpTuf\ComposerStager\API\Core\BeginnerInterface $beginner
   *   The beginner service.
   * @param \PhpTuf\ComposerStager\API\Core\StagerInterface $stager
   *   The stager service.
   * @param \PhpTuf\ComposerStager\API\Core\CommitterInterface $committer
   *   The committer service.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher service.
   * @param \Drupal\Core\TempStore\SharedTempStoreFactory $tempStoreFactory
   *   The shared tempstore factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \PhpTuf\ComposerStager\API\Path\Factory\PathFactoryInterface $pathFactory
   *   The path factory service.
   * @param \Drupal\package_manager\FailureMarker $failureMarker
   *   The failure marker service.
   */
  public function __construct(
    private readonly LockBackendInterface $lock,
    private readonly CronUpdateStage $cronUpdateRunner,
    private readonly MailManagerInterface $mailManager,
    private readonly StatusCheckMailer $statusCheckMailer,
    private readonly ReleaseChooser $releaseChooser,
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
  ) {
    parent::__construct($composerInspector, $pathLocator, $beginner, $stager, $committer, $fileSystem, $eventDispatcher, $tempStoreFactory, $time, $pathFactory, $failureMarker);
  }

  /**
   * Returns the release of Drupal core to update to, if any.
   *
   * @return \Drupal\update\ProjectRelease|null
   *   The release of Drupal core to which we will update, or NULL if there is
   *   nothing to update to.
   */
  public function getTargetRelease(): ?ProjectRelease {
    return $this->releaseChooser->getLatestInInstalledMinor($this);
  }

  /**
   * {@inheritdoc}
   */
  final public function begin(array $project_versions, ?int $timeout = 300): never {
    // Unattended updates should never be started using this method. They should
    // only be done by ::performUpdate(), which has a strong opinion about which
    // release to update to and will call ::setProcessStatus(). Throwing an
    // exception here is just to enforce this boundary. To update to a specific
    // version of core, use \Drupal\automatic_updates\UpdateStage::begin()
    // (which is called in::performUpdate() to start the update to the target
    // version of core chosen by ::getTargetRelease()).
    throw new \BadMethodCallException(__METHOD__ . '() cannot be called directly.');
  }

  /**
   * Performs the update.
   *
   * @param bool $is_from_web
   *   Whether the current process was started from a web request. This is not
   *   used by this method specifically, but it's passed through to post-apply
   *   to ensure that status checks are only run as the web server user if the
   *   update is being trigger via the web.
   *
   * @return bool
   *   Returns TRUE if any update was attempted, otherwise FALSE.
   */
  public function performUpdate(bool $is_from_web = FALSE): bool {
    if ($this->cronUpdateRunner->getMode() === CronUpdateStage::DISABLED) {
      return FALSE;
    }

    $next_release = $this->getTargetRelease();
    if (!$next_release) {
      return FALSE;
    }
    $target_version = $next_release->getVersion();
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
    if (!$this->lock->acquire('cron', 600)) {
      $this->logger->error('Unable to start Drupal core update because cron is running.');
      return $update_started;
    }

    // Do the bulk of the update in its own try-catch structure, so that we can
    // handle any exceptions or validation errors consistently, and destroy the
    // stage regardless of whether the update succeeds.
    try {
      $update_started = TRUE;
      // @see ::begin()
      $stage_id = parent::begin(['drupal' => $target_version], 300);
      $this->stage();
      $this->apply();
    }
    catch (\Throwable $e) {
      $this->lock->release('cron');
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
    $this->triggerPostApply($stage_id, $installed_version, $target_version, $is_from_web);
    return TRUE;
  }

  /**
   * Runs the post apply command.
   *
   * @param string $stage_id
   *   The ID of the current stage.
   * @param string $start_version
   *   The version of Drupal core that started the update.
   * @param string $target_version
   *   The version of Drupal core to which we are updating.
   * @param bool $is_from_web
   *   Whether or not the update command was run from the web.
   */
  protected function triggerPostApply(string $stage_id, string $start_version, string $target_version, bool $is_from_web): void {
    $alias = Drush::aliasManager()->getSelf();

    $output = Drush::processManager()
      ->drush($alias, 'auto-update', [], [
        'post-apply' => TRUE,
        'stage-id' => $stage_id,
        'from-version' => $start_version,
        'to-version' => $target_version,
        'is-from-web' => $is_from_web,
      ])
      ->mustRun()
      ->getOutput();
    // Ensure the output of the sub-process is visible.
    Drush::output()->write($output);
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
   */
  public function handlePostApply(string $stage_id, string $installed_version, string $target_version): void {
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
    $this->lock->release('cron');

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
  }

}
