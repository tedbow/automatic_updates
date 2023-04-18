<?php

declare(strict_types = 1);

namespace Drupal\package_manager\PathExcluder;

use Drupal\package_manager\Event\CollectPathsToExcludeEvent;

/**
 * Contains methods for excluding paths from stage operations.
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
   * @param \Drupal\package_manager\Event\CollectPathsToExcludeEvent|\Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   * @param string[] $paths
   *   The paths to exclude. These should be relative to the web root, and will
   *   be made relative to the project root.
   */
  protected function excludeInWebRoot(CollectPathsToExcludeEvent $event, array $paths): void {
    $web_root = $this->pathLocator->getWebRoot();
    if ($web_root) {
      $web_root .= '/';
    }

    foreach ($paths as $path) {
      // Make the path relative to the project root by prefixing the web root.
      $event->add([$web_root . $path]);
    }
  }

  /**
   * Flags paths to be excluded, relative to the project root.
   *
   * @param \Drupal\package_manager\Event\CollectPathsToExcludeEvent|\Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   * @param string[] $paths
   *   The paths to exclude. Absolute paths will be made relative to the project
   *   root; relative paths will be assumed to already be relative to the
   *   project root, and excluded as given.
   */
  protected function excludeInProjectRoot(CollectPathsToExcludeEvent $event, array $paths): void {
    $project_root = $this->pathLocator->getProjectRoot();

    foreach ($paths as $path) {
      if (str_starts_with($path, '/')) {
        if (!str_starts_with($path, $project_root)) {
          throw new \LogicException("$path is not inside the project root: $project_root.");
        }
      }

      // Make absolute paths relative to the project root.
      $path = str_replace($project_root, '', $path);
      $path = ltrim($path, '/');
      $event->add([$path]);
    }
  }

  /**
   * Finds all directories in the project root matching the given name.
   *
   * @param string $directory_name
   *   The directory name to scan for.
   *
   * @return string[]
   *   All discovered absolute paths matching the given directory name.
   */
  protected function scanForDirectoriesByName(string $directory_name): array {
    $flags = \FilesystemIterator::UNIX_PATHS;
    $flags |= \FilesystemIterator::CURRENT_AS_SELF;
    $directories_tree = new \RecursiveDirectoryIterator($this->pathLocator->getProjectRoot(), $flags);
    $filtered_directories = new \RecursiveIteratorIterator($directories_tree, \RecursiveIteratorIterator::SELF_FIRST);
    $matched_directories = new \CallbackFilterIterator($filtered_directories,
      fn (\RecursiveDirectoryIterator $current) => $current->isDir() && $current->getFilename() === $directory_name
    );
    return array_keys(iterator_to_array($matched_directories));
  }

}
