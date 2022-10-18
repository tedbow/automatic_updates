<?php

namespace Drupal\automatic_updates;

use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\package_manager\Exception\ApplyFailedException;
use Drupal\package_manager\Exception\StageValidationException;
use Drupal\package_manager\ProjectInfo;
use Drupal\update\ProjectRelease;
use GuzzleHttp\Psr7\Uri as GuzzleUri;
use Symfony\Component\HttpFoundation\Response;

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
   * The mail manager service.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The language manager service.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Constructs a CronUpdater object.
   *
   * @param \Drupal\automatic_updates\ReleaseChooser $release_chooser
   *   The cron release chooser service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param mixed ...$arguments
   *   Additional arguments to pass to the parent constructor.
   */
  public function __construct(ReleaseChooser $release_chooser, LoggerChannelFactoryInterface $logger_factory, MailManagerInterface $mail_manager, LanguageManagerInterface $language_manager, StateInterface $state, ...$arguments) {
    parent::__construct(...$arguments);
    $this->releaseChooser = $release_chooser;
    $this->logger = $logger_factory->get('automatic_updates');
    $this->mailManager = $mail_manager;
    $this->languageManager = $language_manager;
    $this->state = $state;
  }

  /**
   * Handles updates during cron.
   *
   * @param int|null $timeout
   *   (optional) How long to allow the file copying operation to run before
   *   timing out, in seconds, or NULL to never time out. Defaults to 300
   *   seconds.
   */
  public function handleCron(?int $timeout = 300): void {
    if ($this->getMode() === static::DISABLED) {
      return;
    }

    $next_release = $this->getTargetRelease();
    if ($next_release) {
      $this->performUpdate($next_release->getVersion(), $timeout);
    }
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
  final public function begin(array $project_versions, ?int $timeout = 300): string {
    // Unattended updates should never be started using this method. They should
    // only be done by ::handleCron(), which has a strong opinion about which
    // release to update to. Throwing an exception here is just to enforce this
    // boundary. To update to a specific version of core, use
    // \Drupal\automatic_updates\Updater::begin() (which is called in
    // ::performUpdate() to start the update to the target version of core
    // chosen by ::handleCron()).
    throw new \BadMethodCallException(__METHOD__ . '() cannot be called directly.');
  }

  /**
   * Performs the update.
   *
   * @param string $target_version
   *   The target version of Drupal core.
   * @param int|null $timeout
   *   How long to allow the operation to run before timing out, in seconds, or
   *   NULL to never time out.
   */
  private function performUpdate(string $target_version, ?int $timeout): void {
    $project_info = new ProjectInfo('drupal');

    $installed_version = $project_info->getInstalledVersion();
    if (empty($installed_version)) {
      $this->logger->error('Unable to determine the current version of Drupal core.');
      return;
    }

    // Do the bulk of the update in its own try-catch structure, so that we can
    // handle any exceptions or validation errors consistently, and destroy the
    // stage regardless of whether the update succeeds.
    try {
      // @see ::begin()
      $stage_id = parent::begin(['drupal' => $target_version], $timeout);
      $this->stage();
      $this->apply();
    }
    catch (\Throwable $e) {
      // Send notifications about the failed update.
      $mail_params = [
        'previous_version' => $installed_version,
        'target_version' => $target_version,
        'error_message' => $e->getMessage(),
      ];
      if ($e instanceof ApplyFailedException || $e->getPrevious() instanceof ApplyFailedException) {
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

      foreach ($this->getEmailRecipients() as $email => $langcode) {
        $this->mailManager->mail('automatic_updates', $key, $email, $langcode, $mail_params);
      }
      $this->logger->error($e->getMessage());

      // If an error occurred during the pre-create event, the stage will be
      // marked as available and we shouldn't try to destroy it, since the stage
      // must be claimed in order to be destroyed.
      if (!$this->isAvailable()) {
        $this->destroy();
      }
      return;
    }

    // Perform a subrequest to run ::postApply(), which needs to be done in a
    // separate request.
    // @see parent::apply()
    $url = Url::fromRoute('automatic_updates.cron.post_apply', [
      'stage_id' => $stage_id,
      'installed_version' => $installed_version,
      'target_version' => $target_version,
      'key' => $this->state->get('system.cron_key'),
    ]);
    $this->triggerPostApply($url);
  }

  /**
   * Executes a subrequest to run post-apply tasks.
   *
   * @param \Drupal\Core\Url $url
   *   The URL of the post-apply handler.
   */
  protected function triggerPostApply(Url $url): void {
    $url = $url->setAbsolute()->toString();

    // If we're using a single-threaded web server (e.g., the built-in PHP web
    // server used in build tests), allow the post-apply request to be sent to
    // an alternate port.
    // @todo If using the built-in PHP web server, validate that this port is
    //   set in https://www.drupal.org/i/3293146.
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
    // We use \Drupal::service() here because, given what we inherit from the
    // parent class, there's no clean way to inject the shared tempstore
    // factory.
    $this->tempStore = \Drupal::service('tempstore.shared')
      ->get('package_manager_stage', $owner);

    $this->claim($stage_id);

    // Run post-apply tasks in their own try-catch block so that, if anything
    // raises an exception, we'll log it and proceed to destroy the stage as
    // soon as possible (which is also what we do in ::performUpdate()).
    try {
      $this->postApply();

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
      foreach ($this->getEmailRecipients() as $recipient => $langcode) {
        $this->mailManager->mail('automatic_updates', 'cron_successful', $recipient, $langcode, $mail_params);
      }
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
    catch (StageValidationException $e) {
      $this->logger->error($e->getMessage());
    }

    return new Response();
  }

  /**
   * Retrieves preferred language to send email.
   *
   * @param string $recipient
   *   The email address of the recipient.
   *
   * @return string
   *   The preferred language of the recipient.
   */
  protected function getEmailLangcode(string $recipient): string {
    $user = user_load_by_mail($recipient);
    if ($user) {
      return $user->getPreferredLangcode();
    }
    return $this->languageManager->getDefaultLanguage()->getId();
  }

  /**
   * Returns an array of people to email with success or failure notifications.
   *
   * @return string[]
   *   An array whose keys are the email addresses to send notifications to, and
   *   values are the langcodes that they should be emailed in.
   */
  protected function getEmailRecipients(): array {
    $recipients = $this->configFactory->get('update.settings')
      ->get('notification.emails');
    $emails = [];
    foreach ($recipients as $recipient) {
      $emails[$recipient] = $this->getEmailLangcode($recipient);
    }
    return $emails;
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
   */
  final public function getMode(): string {
    if (self::$disabled) {
      return static::DISABLED;
    }
    $mode = $this->configFactory->get('automatic_updates.settings')->get('cron');
    return $mode ?: CronUpdater::SECURITY;
  }

}
