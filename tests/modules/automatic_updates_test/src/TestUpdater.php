<?php

namespace Drupal\automatic_updates_test;

use Drupal\automatic_updates\Updater;

/**
 * A testing updater that allows arbitrary active and stage directories.
 */
class TestUpdater extends Updater {

  /**
   * The active directory to use, if different from the default.
   *
   * @var string
   */
  public $activeDirectory;

  /**
   * The stage directory to use, if different from the default.
   *
   * @var string
   */
  public $stageDirectory;

  /**
   * {@inheritdoc}
   */
  public function getActiveDirectory(): string {
    return $this->activeDirectory ?: parent::getActiveDirectory();
  }

  /**
   * {@inheritdoc}
   */
  public function getStageDirectory(): string {
    return $this->stageDirectory ?: parent::getStageDirectory();
  }

}
