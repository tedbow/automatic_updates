<?php

namespace Drupal\automatic_updates\Event;

use Drupal\package_manager\ComposerUtility;

/**
 * Event fired when checking if the site could perform an update.
 */
class ReadinessCheckEvent extends UpdateEvent {

  use PackagesAwareTrait;

  /**
   * Constructs a ReadinessCheckEvent object.
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
