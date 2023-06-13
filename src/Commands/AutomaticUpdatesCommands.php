<?php

declare(strict_types = 1);

namespace Drupal\automatic_updates\Commands;

use Drupal\automatic_updates\DrushUpdateStage;
use Drupal\automatic_updates\StatusCheckMailer;
use Drupal\automatic_updates\Validation\StatusChecker;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\package_manager\DebuggerTrait;
use Drush\Commands\DrushCommands;
use function Psy\debug;

/**
 * Contains Drush commands for Automatic Updates.
 *
 * @internal
 *   This is an internal part of Automatic Updates and may be changed or removed
 *   at any time without warning. It should not be called directly, and external
 *   code should not interact with it.
 */
final class AutomaticUpdatesCommands extends DrushCommands {

  use DebuggerTrait;

  /**
   * Constructs a AutomaticUpdatesCommands object.
   *
   * @param \Drupal\automatic_updates\DrushUpdateStage $stage
   *   The console cron updater service.
   * @param \Drupal\automatic_updates\Validation\StatusChecker $statusChecker
   *   The status checker service.
   * @param \Drupal\automatic_updates\StatusCheckMailer $statusCheckMailer
   *   The status check mailer service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   */
  public function __construct(
    private readonly DrushUpdateStage $stage,
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
   *
   * @command auto-update
   *
   * @throws \LogicException
   *   If the --post-apply option is provided without the --stage-id,
   *   --from-version, and --to-version options.
   */
  public function autoUpdate(array $options = ['post-apply' => FALSE, 'stage-id' => NULL, 'from-version' => NULL, 'to-version' => NULL]) {
    $this->debugOut(print_r($options, true), 0);
    $out = "the package_manager_bypass exists:" . (\Drupal::moduleHandler()->moduleExists('package_manager_bypass') ? 'yes' : 'no');
    $this->debugOut($out);
    $io = $this->io();

    // The second half of the update process (post-apply etc.) is done by this
    // exact same command, with some additional flags, in a separate process to
    // ensure that the system is in a consistent state.
    // @see \Drupal\automatic_updates\DrushUpdateStage::triggerPostApply()
    if ($options['post-apply']) {
      if (empty($options['stage-id']) || empty($options['from-version']) || empty($options['to-version'])) {
        throw new \LogicException("The post-apply option is for internal use only. It should never be passed directly.");
      }
      $message = sprintf('Drupal core was successfully updated to %s!', $options['to-version']);
      $io->success($message);

      $io->info('Running post-apply tasks and final clean-up...');
      $this->stage->handlePostApply($options['stage-id'], $options['from-version'], $options['to-version']);
      $this->runStatusChecks();
    }
    else {
      if ($this->stage->getMode() === DrushUpdateStage::DISABLED) {
        $this->debugOut("***disabled");
        $io->error('Automatic updates are disabled.');
        return;
      }

      $release = $this->stage->getTargetRelease();
      if ($release) {
        $this->debugOut("release is " . $release->getVersion());
        $message = sprintf('Updating Drupal core to %s. This may take a while.', $release->getVersion());
        $io->info($message);
        $this->stage->performUpdate($release->getVersion(), 300);
      }
      else {
        $this->debugOut("release none");
        $io->info("There is no Drupal core update available.");
        $this->runStatusChecks();
      }
    }
  }

  /**
   * Runs status checks, and sends failure notifications if necessary.
   */
  private function runStatusChecks(): void {
    $method = $this->configFactory->get('automatic_updates.settings')
      ->get('unattended.method');

    // To ensure consistent results, only run the status checks if we're
    // explicitly configured to do unattended updates on the command line.
    if ($method === 'console') {
      $last_results = $this->statusChecker->getResults();
      $this->statusCheckMailer->sendFailureNotifications($last_results, $this->statusChecker->run()->getResults());
    }
  }

}
