<?php

declare(strict_types = 1);

namespace Drupal\automatic_updates\Commands;

use Drupal\automatic_updates\DrushUpdateStage;
use Drush\Commands\DrushCommands;

/**
 * Contains Drush commands for Automatic Updates.
 *
 * @internal
 *   This is an internal part of Automatic Updates and may be changed or removed
 *   at any time without warning. It should not be called directly, and external
 *   code should not interact with it.
 */
final class AutomaticUpdatesCommands extends DrushCommands {

  /**
   * Constructs a AutomaticUpdatesCommands object.
   *
   * @param \Drupal\automatic_updates\DrushUpdateStage $stage
   *   The console cron updater service.
   */
  public function __construct(private readonly DrushUpdateStage $stage) {
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
    }
    else {
      if ($this->stage->getMode() === DrushUpdateStage::DISABLED) {
        $io->error('Automatic updates are disabled.');
        return;
      }

      $release = $this->stage->getTargetRelease();
      if ($release) {
        $message = sprintf('Updating Drupal core to %s. This may take a while.', $release->getVersion());
        $io->info($message);
        $this->stage->performUpdate($release->getVersion(), 300);
      }
      else {
        $io->info("There is no Drupal core update available.");
      }
    }
  }

}
