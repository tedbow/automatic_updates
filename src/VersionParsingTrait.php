<?php

namespace Drupal\automatic_updates;

use Drupal\Core\Extension\ExtensionVersion;

/**
 * Common function for parsing version traits.
 *
 * @internal
 *   This trait may be removed in patch or minor versions.
 */
trait VersionParsingTrait {

  /**
   * Gets the patch number from a version string.
   *
   * @todo Move this method to \Drupal\Core\Extension\ExtensionVersion in
   *   https://www.drupal.org/i/3261744.
   *
   * @param string $version_string
   *   The version string.
   *
   * @return string|null
   *   The patch number if available, otherwise NULL.
   */
  protected static function getPatchVersion(string $version_string): ?string {
    $version_extra = ExtensionVersion::createFromVersionString($version_string)
      ->getVersionExtra();
    if ($version_extra) {
      $version_string = str_replace("-$version_extra", '', $version_string);
    }
    $version_parts = explode('.', $version_string);
    return count($version_parts) === 3 ? $version_parts[2] : NULL;
  }

}
