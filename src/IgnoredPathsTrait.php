<?php

namespace Drupal\automatic_updates;

/**
 * Provide a helper to check if file paths are ignored.
 */
trait IgnoredPathsTrait {

  /**
   * Check if the file path is ignored.
   *
   * @param string $file_path
   *   The file path.
   *
   * @return bool
   *   TRUE if file path is ignored, else FALSE.
   */
  protected function isIgnoredPath($file_path) {
    $paths = $this->getConfigFactory()->get('automatic_updates.settings')->get('ignored_paths');
    if ($this->getPathMatcher()->matchPath($file_path, $paths)) {
      return TRUE;
    }
  }

  /**
   * Gets the config factory.
   *
   * @return \Drupal\Core\Config\ConfigFactoryInterface
   *   The config factory.
   */
  protected function getConfigFactory() {
    if (isset($this->configFactory)) {
      return $this->configFactory;
    }
    return \Drupal::configFactory();
  }

  /**
   * Get the path matcher service.
   *
   * @return \Drupal\Core\Path\PathMatcherInterface
   *   The path matcher.
   */
  protected function getPathMatcher() {
    if (isset($this->pathMatcher)) {
      return $this->pathMatcher;
    }
    return \Drupal::service('path.matcher');
  }

}
