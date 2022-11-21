<?php

declare(strict_types = 1);

namespace Drupal\package_manager\PathExcluder;

use Drupal\package_manager\Event\CollectIgnoredPathsEvent;
use Drupal\package_manager\PathLocator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Finder\Finder;

/**
 * Excludes .git directories from staging operations.
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
   */
  public function __construct(PathLocator $path_locator) {
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
   * Excludes .git directories from staging operations.
   *
   * @param \Drupal\package_manager\Event\CollectIgnoredPathsEvent $event
   *   The event object.
   */
  public function excludeGitDirectories(CollectIgnoredPathsEvent $event): void {
    // Find all .git directories in the project and exclude them. We cannot do
    // this with FileSystemInterface::scanDirectory() because it unconditionally
    // excludes anything starting with a dot.
    $finder = Finder::create()
      ->in($this->pathLocator->getProjectRoot())
      ->directories()
      ->name('.git')
      ->ignoreVCS(FALSE)
      ->ignoreDotFiles(FALSE);

    $paths = [];
    foreach ($finder as $git_directory) {
      $paths[] = $git_directory->getPathname();
    }
    $this->excludeInProjectRoot($event, $paths);
  }

}
