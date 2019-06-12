<?php

namespace Drupal\automatic_updates;

use PackageVersions\Versions;

/**
 * Provide a helper to get project info.
 */
trait ProjectInfoTrait {

  /**
   * Get the extension version.
   *
   * @param string $extension_name
   *   The extension name.
   * @param array $info
   *   The extension's info.
   *
   * @return string|null
   *   The version or NULL if undefined.
   */
  protected function getExtensionVersion($extension_name, array $info) {
    if (isset($info['version']) && strpos($info['version'], '-dev') === FALSE) {
      return $info['version'];
    }
    $composer_json = $this->getComposerJson($extension_name, $info);
    $extension_name = isset($composer_json['name']) ? $composer_json['name'] : $extension_name;
    try {
      $version = Versions::getVersion($extension_name);
      $version = $this->getSuffix($version, '@', $version);
      // If we do not have a core compatibility tagged git branch, we're
      // dealing with a  dev-master branch that cannot be updated in place.
      return substr($version, 0, 3) === \Drupal::CORE_COMPATIBILITY ? $version : NULL;
    }
    catch (\OutOfBoundsException $exception) {
      \Drupal::logger('automatic_updates')->error('Version cannot be located for @extension', ['@extension' => $extension_name]);
    }
  }

  /**
   * Get the extension's project name.
   *
   * @param string $extension_name
   *   The extension name.
   * @param array $info
   *   The extension's info.
   *
   * @return string
   *   The project name or fallback to extension name if project is undefined.
   */
  protected function getProjectName($extension_name, array $info) {
    $project_name = $extension_name;
    if (isset($info['project'])) {
      $project_name = $info['project'];
    }
    elseif ($composer_json = $this->getComposerJson($extension_name, $info)) {
      if (isset($composer_json['name'])) {
        $project_name = $this->getSuffix($composer_json['name'], '/', $extension_name);
      }
    }
    return $project_name;
  }

  /**
   * Get string suffix.
   *
   * @param string $string
   *   The string to parse.
   * @param string $needle
   *   The needle.
   * @param string $default
   *   The default value.
   *
   * @return string
   *   The sub string.
   */
  protected function getSuffix($string, $needle, $default) {
    $pos = strrpos($string, $needle);
    return $pos === FALSE ? $default : substr($string, ++$pos);
  }

  /**
   * Get the composer.json as a JSON array.
   *
   * @param string $extension_name
   *   The extension name.
   * @param array $info
   *   The extension's info.
   *
   * @return array|null
   *   The composer.json as an array or NULL.
   */
  protected function getComposerJson($extension_name, array $info) {
    try {
      if ($directory = drupal_get_path($info['type'], $extension_name)) {
        $composer_json = $directory . DIRECTORY_SEPARATOR . 'composer.json';
        if (file_exists($composer_json)) {
          return json_decode(file_get_contents($composer_json), TRUE);
        }
      }
    }
    catch (\Throwable $exception) {
      \Drupal::logger('automatic_updates')->error('Composer.json could not be located for @extension', ['@extension' => $extension_name]);
    }
  }

}
