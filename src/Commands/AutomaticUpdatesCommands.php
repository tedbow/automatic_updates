<?php

declare(strict_types = 1);

namespace Drupal\automatic_updates\Commands;

use Drupal\automatic_updates\CronUpdateRunner;
use Drupal\automatic_updates\ConsoleUpdateStage;
use Drupal\automatic_updates\StatusCheckMailer;
use Drupal\automatic_updates\Validation\StatusChecker;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drush\Commands\DrushCommands;

/**
 * Contains Drush commands for Automatic Updates.
 *
 * @internal
 *   This is an internal part of Automatic Updates and may be changed or removed
 *   at any time without warning. It should not be called directly, and external
 *   code should not interact with it.
 *
 * @todo Either remove this command completely or make it just call the new
 *   Symfony Console command that will be added in https://drupal.org/i/3360485.
 */
class AutomaticUpdatesCommands extends DrushCommands {

  /**
   * Constructs a AutomaticUpdatesCommands object.
   *
   * @param \Drupal\automatic_updates\CronUpdateRunner $cronUpdateRunner
   *   The cron update runner service.
   * @param \Drupal\automatic_updates\ConsoleUpdateStage $stage
   *   The console cron updater service.
   * @param \Drupal\automatic_updates\Validation\StatusChecker $statusChecker
   *   The status checker service.
   * @param \Drupal\automatic_updates\StatusCheckMailer $statusCheckMailer
   *   The status check mailer service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   */
  public function __construct(
    private readonly CronUpdateRunner $cronUpdateRunner,
    private readonly ConsoleUpdateStage $stage,
    private readonly StatusChecker $statusChecker,
    private readonly StatusCheckMailer $statusCheckMailer,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct();
  }

  /**
   * Automatically updates Drupal core.
   *
   * @usage auto-update
   *   Automatically updates Drupal core, if any updates are available.
   *
   * @option $post-apply Internal use only.
   * @option $stage-id Internal use only.
   * @option $from-version Internal use only.
   * @option $to-version Internal use only.
   * @option $is-from-web Whether the request was triggered from the web.
   *
   * @command auto-update
   *
   * @throws \LogicException
   *   If the --post-apply option is provided without the --stage-id,
   *   --from-version, and --to-version options.
   */
  public function autoUpdate(array $options = ['post-apply' => FALSE, 'stage-id' => NULL, 'from-version' => NULL, 'to-version' => NULL, 'is-from-web' => FALSE]) {
    $io = $this->io();

    // The second half of the update process (post-apply etc.) is done by this
    // exact same command, with some additional flags, in a separate process to
    // ensure that the system is in a consistent state.
    // @see \Drupal\automatic_updates\ConsoleUpdateStage::triggerPostApply()
    if ($options['post-apply']) {
      if (empty($options['stage-id']) || empty($options['from-version']) || empty($options['to-version'])) {
        throw new \LogicException("The post-apply option is for internal use only. It should never be passed directly.");
      }
      $message = sprintf('Drupal core was successfully updated to %s!', $options['to-version']);
      $io->success($message);

      $io->info('Running post-apply tasks and final clean-up...');
      $this->stage->handlePostApply($options['stage-id'], $options['from-version'], $options['to-version']);
      $this->runStatusChecks($options['is-from-web']);
    }
    else {
      if ($this->cronUpdateRunner->getMode() !== CronUpdateRunner::DISABLED) {
        $release = $this->stage->getTargetRelease();
        if ($release) {
          $message = sprintf('Updating Drupal core to %s. This may take a while.', $release->getVersion());
          $io->info($message);
          $this->stage->performUpdate($options['is-from-web']);
          return;
        }
        else {
          $io->info("There is no Drupal core update available.");
        }
      }

      $this->runStatusChecks($options['is-from-web']);
    }
  }

  /**
   * Runs status checks, and sends failure notifications if necessary.
   */
  private function runStatusChecks(bool $is_from_web): void {
    $method = $this->configFactory->get('automatic_updates.settings')
      ->get('unattended.method');

    $last_results = $this->statusChecker->getResults();
    $last_run_time = $this->statusChecker->getLastRunTime();
    // Do not run status checks more than once an hour unless there are no results
    // available.
    $needs_run = $last_results === NULL || !$last_run_time || \Drupal::time()->getRequestTime() - $last_run_time > 3600;

    // To ensure consistent results, only run the status checks if we're
    // explicitly configured to do unattended updates on the command line.
    if ($needs_run && ($method === 'web' && $is_from_web) || $method === 'console') {
      $this->statusChecker->run();
      // Only try to send failure notifications if unattended updates are
      // enabled.
      if ($this->cronUpdateRunner->getMode() !== CronUpdateRunner::DISABLED) {
        $this->statusCheckMailer->sendFailureNotifications($last_results, $this->statusChecker->getResults());
      }
    }
  }

}
