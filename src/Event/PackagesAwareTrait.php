<?php

namespace Drupal\automatic_updates\Event;

/**
 * Common functionality for events which can carry desired package versions.
 */
trait PackagesAwareTrait {

  /**
   * The desired package versions to update to, keyed by package name.
   *
   * @var string[]
   */
  protected $packageVersions;

  /**
   * Constructs a PreStartEvent.
   *
   * @param string[] $package_versions
   *   (optional) The desired package versions to update to, keyed by package
   *   name.
   */
  public function __construct(array $package_versions = []) {
    $this->packageVersions = $package_versions;
  }

  /**
   * Returns the desired package versions to update to.
   *
   * @return string[]
   *   The desired package versions to update to, keyed by package name.
   */
  public function getPackageVersions(): array {
    return $this->packageVersions;
  }

}
