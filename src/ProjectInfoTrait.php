<?php

namespace Drupal\automatic_updates;

use PackageVersions\Versions;

/**
 * Provide a helper to get project info.
 */
trait ProjectInfoTrait {

  /**
   * Get extension list.
   *
   * @param string $extension_type
   *   The extension type.
   *
   * @return \Drupal\Core\Extension\ExtensionList
   *   The extension list service.
   */
  protected function getExtensionList($extension_type) {
    if (isset($this->{$extension_type})) {
      $list = $this->{$extension_type};
    }
    else {
      $list = \Drupal::service("extension.list.$extension_type");
    }
    return $list;
  }

  /**
   * Returns an array of info files information of available extensions.
   *
   * @param string $extension_type
   *   The extension type.
   *
   * @return array
   *   An associative array of extension information arrays, keyed by extension
   *   name.
   */
  protected function getInfos($extension_type) {
    $file_paths = $this->getExtensionList($extension_type)->getPathnames();
    $infos = $this->getExtensionList($extension_type)->getAllAvailableInfo();
    return array_map(function ($key, array $info) use ($file_paths) {
      $info['packaged'] = $info['project'] ?? FALSE;
      $info['project'] = $this->getProjectName($key, $info);
      $info['install path'] = $file_paths[$key] ? dirname($file_paths[$key]) : '';
      $info['version'] = $this->getExtensionVersion($info);
      return $info;
    }, array_keys($infos), $infos);
  }

  /**
   * Get the extension version.
   *
   * @param array $info
   *   The extension's info.
   *
   * @return string|null
   *   The version or NULL if undefined.
   */
  protected function getExtensionVersion(array $info) {
    $extension_name = $info['project'];
    if (isset($info['version']) && strpos($info['version'], '-dev') === FALSE) {
      return $info['version'];
    }
    // Handle experimental modules from core.
    if (substr($info['install path'], 0, 4) === "core") {
      return $this->getExtensionList('module')->get('system')->info['version'];
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
    if ($project_name === 'system') {
      $project_name = 'drupal';
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
