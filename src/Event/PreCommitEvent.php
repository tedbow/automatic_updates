<?php

namespace Drupal\automatic_updates\Event;

use Drupal\package_manager\ComposerUtility;

/**
 * Event fired before staged changes are copied into the active site.
 */
class PreCommitEvent extends UpdateEvent {

  use ExcludedPathsTrait;

  /**
   * The Composer utility object for the stage directory.
   *
   * @var \Drupal\package_manager\ComposerUtility
   */
  protected $stageComposer;

  /**
   * Constructs a new PreCommitEvent object.
   *
   * @param \Drupal\package_manager\ComposerUtility $active_composer
   *   A Composer utility object for the active directory.
   * @param \Drupal\package_manager\ComposerUtility $stage_composer
   *   A Composer utility object for the stage directory.
   */
  public function __construct(ComposerUtility $active_composer, ComposerUtility $stage_composer) {
    parent::__construct($active_composer);
    $this->stageComposer = $stage_composer;
  }

  /**
   * Returns a Composer utility object for the stage directory.
   *
   * @return \Drupal\package_manager\ComposerUtility
   *   The Composer utility object for the stage directory.
   */
  public function getStageComposer(): ComposerUtility {
    return $this->stageComposer;
  }

}
