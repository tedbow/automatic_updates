<?php

namespace Drupal\automatic_updates\Event;

use Drupal\package_manager\ComposerUtility;

/**
 * Event fired before an update begins.
 *
 * This event is fired before any files are staged. Validation results added
 * by subscribers are not cached.
 */
class PreStartEvent extends UpdateEvent {

  use ExcludedPathsTrait;
  use PackagesAwareTrait;

  /**
   * Constructs a PreStartEvent object.
   *
   * @param \Drupal\package_manager\ComposerUtility $active_composer
   *   A Composer utility object for the active directory.
   * @param string[] $package_versions
   *   (optional) The desired package versions to update to, keyed by package
   *   name.
   */
  public function __construct(ComposerUtility $active_composer, array $package_versions = []) {
    parent::__construct($active_composer);
    $this->packageVersions = $package_versions;
  }

}
