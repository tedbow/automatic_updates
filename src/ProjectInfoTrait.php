<?php

namespace Drupal\automatic_updates;

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
    array_walk($infos, function (array &$info, $key) use ($file_paths) {
      $info['packaged'] = isset($info['datestamp']) ? $info['datestamp'] : FALSE;
      $info['install path'] = $file_paths[$key] ? dirname($file_paths[$key]) : '';
      $info['project'] = $this->getProjectName($key, $info);
      $info['version'] = $this->getExtensionVersion($info);
    });
    $system = isset($infos['system']) ? $infos['system'] : NULL;
    $infos = array_filter($infos, static function (array $info, $project_name) {
      return $info && $info['project'] === $project_name;
    }, ARRAY_FILTER_USE_BOTH);
    if ($system) {
      $infos['drupal'] = $system;
    }
    return $infos;
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
    if (strpos($info['install path'], 'core') === 0) {
      return $this->getExtensionList('module')->get('system')->info['version'];
    }
    \Drupal::logger('automatic_updates')->error('Version cannot be located for @extension', ['@extension' => $extension_name]);
    return NULL;
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
    if (strpos($info['install path'], 'core') === 0) {
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
    return NULL;
  }

}
