<?php

declare(strict_types = 1);

namespace Drupal\automatic_updates;

use Drush\Drush;

/**
 * An updater that runs via a Drush command.
 */
final class DrushUpdateStage extends CronUpdateStage {

  /**
   * {@inheritdoc}
   */
  protected function triggerPostApply(string $stage_id, string $start_version, string $target_version): void {
    $alias = Drush::aliasManager()->getSelf();

    $output = Drush::processManager()
      ->drush($alias, 'auto-update', [], [
        'post-apply' => TRUE,
        'stage-id' => $stage_id,
        'from-version' => $start_version,
        'to-version' => $target_version,
      ])
      ->mustRun()
      ->getOutput();
    // Ensure the output of the sub-process is visible.
    Drush::output()->write($output);
  }

  /**
   * {@inheritdoc}
   */
  public function performUpdate(string $target_version, ?int $timeout): bool {
    // Overridden to expose this method to calling code.
    return parent::performUpdate($target_version, $timeout);
  }

}
