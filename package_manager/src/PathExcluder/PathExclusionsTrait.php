<?php

namespace Drupal\package_manager\PathExcluder;

use Drupal\package_manager\Event\StageEvent;

/**
 * Contains methods for excluding paths from staging operations.
 */
trait PathExclusionsTrait {

  /**
   * The path locator service.
   *
   * @var \Drupal\package_manager\PathLocator
   */
  protected $pathLocator;

  /**
   * Flags paths to be excluded, relative to the web root.
   *
   * This should only be used for paths that, if they exist at all, are
   * *guaranteed* to exist within the web root.
   *
   * @param \Drupal\package_manager\Event\PreCreateEvent|\Drupal\package_manager\Event\PreApplyEvent $event
   *   The event object.
   * @param string[] $paths
   *   The paths to exclude. These should be relative to the web root, and will
   *   be made relative to the project root.
   */
  protected function excludeInWebRoot(StageEvent $event, array $paths): void {
    $web_root = $this->pathLocator->getWebRoot();
    if ($web_root) {
      $web_root .= '/';
    }

    foreach ($paths as $path) {
      // Make the path relative to the project root by prefixing the web root.
      $event->excludePath($web_root . $path);
    }
  }

  /**
   * Flags paths to be excluded, relative to the project root.
   *
   * @param \Drupal\package_manager\Event\PreCreateEvent|\Drupal\package_manager\Event\PreApplyEvent $event
   *   The event object.
   * @param string[] $paths
   *   The paths to exclude. Absolute paths will be made relative to the project
   *   root; relative paths will be assumed to already be relative to the
   *   project root, and excluded as given.
   */
  protected function excludeInProjectRoot(StageEvent $event, array $paths): void {
    $project_root = $this->pathLocator->getProjectRoot();

    foreach ($paths as $path) {
      // Make absolute paths relative to the project root.
      $path = str_replace($project_root, '', $path);
      $path = ltrim($path, '/');
      $event->excludePath($path);
    }
  }

}
