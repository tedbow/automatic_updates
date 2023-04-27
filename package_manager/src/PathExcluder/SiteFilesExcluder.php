<?php

declare(strict_types = 1);

namespace Drupal\package_manager\PathExcluder;

use Drupal\Core\StreamWrapper\LocalStream;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\package_manager\Event\CollectPathsToExcludeEvent;
use Drupal\package_manager\PathLocator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Excludes public and private files from stage operations.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
final class SiteFilesExcluder implements EventSubscriberInterface {

  use PathExclusionsTrait;

  /**
   * Constructs a SiteFilesExcluder object.
   *
   * @param \Drupal\package_manager\PathLocator $path_locator
   *   The path locator service.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $streamWrapperManager
   *   The stream wrapper manager service.
   * @param \Symfony\Component\Filesystem\Filesystem $fileSystem
   *   The Symfony file system service.
   */
  public function __construct(
    PathLocator $path_locator,
    private readonly StreamWrapperManagerInterface $streamWrapperManager,
    private readonly Filesystem $fileSystem
  ) {
    $this->pathLocator = $path_locator;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      CollectPathsToExcludeEvent::class => 'excludeSiteFiles',
    ];
  }

  /**
   * Excludes public and private files from stage operations.
   *
   * @param \Drupal\package_manager\Event\CollectPathsToExcludeEvent $event
   *   The event object.
   */
  public function excludeSiteFiles(CollectPathsToExcludeEvent $event): void {
    // Exclude public and private files. These paths could be either absolute or
    // relative, depending on site settings. If they are absolute, treat them
    // as relative to the project root. Otherwise, treat them as relative to
    // the web root.
    foreach (['public', 'private'] as $scheme) {
      $wrapper = $this->streamWrapperManager->getViaScheme($scheme);
      if ($wrapper instanceof LocalStream) {
        $path = $wrapper->getDirectoryPath();

        if ($this->fileSystem->isAbsolutePath($path)) {
          $this->excludeInProjectRoot($event, [realpath($path)]);
        }
        else {
          $this->excludeInWebRoot($event, [$path]);
        }
      }
    }
  }

}
