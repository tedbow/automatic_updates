<?php

namespace Drupal\package_manager\PathExcluder;

use Drupal\Core\StreamWrapper\LocalStream;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\StageEvent;
use Drupal\package_manager\PathLocator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Excludes public and private files from staging operations.
 */
class SiteFilesExcluder implements EventSubscriberInterface {

  use PathExclusionsTrait;

  /**
   * The stream wrapper manager service.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected $streamWrapperManager;

  /**
   * The Symfony file system service.
   *
   * @var \Symfony\Component\Filesystem\Filesystem
   */
  protected $fileSystem;

  /**
   * Constructs a SiteFilesExcluder object.
   *
   * @param \Drupal\package_manager\PathLocator $path_locator
   *   The path locator service.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $stream_wrapper_manager
   *   The stream wrapper manager service.
   * @param \Symfony\Component\Filesystem\Filesystem $file_system
   *   The Symfony file system service.
   */
  public function __construct(PathLocator $path_locator, StreamWrapperManagerInterface $stream_wrapper_manager, Filesystem $file_system) {
    $this->pathLocator = $path_locator;
    $this->streamWrapperManager = $stream_wrapper_manager;
    $this->fileSystem = $file_system;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      PreCreateEvent::class => 'excludeSiteFiles',
      PreApplyEvent::class => 'excludeSiteFiles',
    ];
  }

  /**
   * Excludes public and private files from staging operations.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   */
  public function excludeSiteFiles(StageEvent $event): void {
    // Ignore public and private files. These paths could be either absolute or
    // relative, depending on site settings. If they are absolute, treat them
    // as relative to the project root. Otherwise, treat them as relative to
    // the web root.
    foreach (['public', 'private'] as $scheme) {
      $wrapper = $this->streamWrapperManager->getViaScheme($scheme);
      if ($wrapper instanceof LocalStream) {
        $path = $wrapper->getDirectoryPath();

        if ($this->fileSystem->isAbsolutePath($path)) {
          $this->excludeInProjectRoot($event, [$path]);
        }
        else {
          $this->excludeInWebRoot($event, [$path]);
        }
      }
    }
  }

}