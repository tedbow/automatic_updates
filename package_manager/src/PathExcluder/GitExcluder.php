<?php

declare(strict_types = 1);

namespace Drupal\package_manager\PathExcluder;

use Drupal\Core\File\FileSystemInterface;
use Drupal\package_manager\Event\CollectIgnoredPathsEvent;
use Drupal\package_manager\PathLocator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Excludes .git directories from stage operations.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
final class GitExcluder implements EventSubscriberInterface {

  use PathExclusionsTrait;

  /**
   * Constructs a GitExcluder object.
   *
   * @param \Drupal\package_manager\PathLocator $path_locator
   *   The path locator service.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   */
  public function __construct(PathLocator $path_locator, protected FileSystemInterface $fileSystem) {
    $this->pathLocator = $path_locator;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      CollectIgnoredPathsEvent::class => 'excludeGitDirectories',
    ];
  }

  /**
   * Excludes .git directories from stage operations.
   *
   * @param \Drupal\package_manager\Event\CollectIgnoredPathsEvent $event
   *   The event object.
   */
  public function excludeGitDirectories(CollectIgnoredPathsEvent $event): void {
    $paths_to_exclude = [];

    $installed_paths = [];
    // Collect the paths of every installed package.
    $installed_packages = $event->stage->getActiveComposer()->getInstalledPackagesData();
    foreach ($installed_packages as $package_data) {
      if (array_key_exists('install_path', $package_data) && !empty($package_data['install_path'])) {
        $installed_paths[] = $this->fileSystem->realpath($package_data['install_path']);
      }
    }
    $paths = $this->scanForDirectoriesByName('.git');
    foreach ($paths as $git_directory) {
      // Don't exclude any `.git` directory that is directly under an installed
      // package's path, since it means Composer probably installed that package
      // from source and therefore needs the `.git` directory in order to update
      // the package.
      if (!in_array($git_directory, $installed_paths, TRUE)) {
        $paths_to_exclude[] = $git_directory;
      }
    }
    $this->excludeInProjectRoot($event, $paths_to_exclude);
  }

}
