<?php

namespace Drupal\package_manager\EventSubscriber;

use Drupal\Core\Database\Connection;
use Drupal\Core\StreamWrapper\LocalStream;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\StageEvent;
use Drupal\package_manager\PathLocator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Defines an event subscriber to exclude certain paths from staging areas.
 */
class ExcludedPathsSubscriber implements EventSubscriberInterface {

  /**
   * The current site path, relative to the Drupal root.
   *
   * @var string
   */
  protected $sitePath;

  /**
   * The Symfony file system service.
   *
   * @var \Symfony\Component\Filesystem\Filesystem
   */
  protected $fileSystem;

  /**
   * The stream wrapper manager service.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected $streamWrapperManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The path locator service.
   *
   * @var \Drupal\package_manager\PathLocator
   */
  protected $pathLocator;

  /**
   * Constructs an ExcludedPathsSubscriber.
   *
   * @param string $site_path
   *   The current site path, relative to the Drupal root.
   * @param \Symfony\Component\Filesystem\Filesystem $file_system
   *   The Symfony file system service.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $stream_wrapper_manager
   *   The stream wrapper manager service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\package_manager\PathLocator $path_locator
   *   The path locator service.
   */
  public function __construct(string $site_path, Filesystem $file_system, StreamWrapperManagerInterface $stream_wrapper_manager, Connection $database, PathLocator $path_locator) {
    $this->sitePath = $site_path;
    $this->fileSystem = $file_system;
    $this->streamWrapperManager = $stream_wrapper_manager;
    $this->database = $database;
    $this->pathLocator = $path_locator;
  }

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

  /**
   * Excludes common paths from staging operations.
   *
   * @param \Drupal\package_manager\Event\PreApplyEvent|\Drupal\package_manager\Event\PreCreateEvent $event
   *   The event object.
   *
   * @see \Drupal\package_manager\Event\ExcludedPathsTrait::excludePath()
   */
  public function ignoreCommonPaths(StageEvent $event): void {
    // Compile two lists of paths to exclude: paths that are relative to the
    // project root, and paths that are relative to the web root.
    $web = $project = [];

    // Always ignore automated test directories. If they exist, they will be in
    // the web root.
    $web[] = 'sites/simpletest';

    // If the core-vendor-hardening plugin (used in the legacy-project template)
    // is present, it may have written security hardening files in the vendor
    // directory. They should always be ignored.
    $vendor_dir = $this->pathLocator->getVendorDirectory();
    $project[] = $vendor_dir . '/web.config';
    $project[] = $vendor_dir . '/.htaccess';

    // Ignore public and private files. These paths could be either absolute or
    // relative, depending on site settings. If they are absolute, treat them
    // as relative to the project root. Otherwise, treat them as relative to
    // the web root.
    $files = array_filter([
      $this->getFilesPath('public'),
      $this->getFilesPath('private'),
    ]);
    foreach ($files as $path) {
      if ($this->fileSystem->isAbsolutePath($path)) {
        $project[] = $path;
      }
      else {
        $web[] = $path;
      }
    }

    // Ignore site-specific settings files, which are always in the web root.
    $settings_files = [
      'settings.php',
      'settings.local.php',
      'services.yml',
    ];
    foreach ($settings_files as $settings_file) {
      $web[] = $this->sitePath . '/' . $settings_file;
      $web[] = 'sites/default/' . $settings_file;
    }

    // If the database is SQLite, it might be located in the active directory
    // and we should ignore it. Always treat it as relative to the project root.
    if ($this->database->driver() === 'sqlite') {
      $options = $this->database->getConnectionOptions();
      $project[] = $options['database'];
      $project[] = $options['database'] . '-shm';
      $project[] = $options['database'] . '-wal';
    }

    // Find all .git directories in the project and exclude them. We cannot do
    // this with FileSystemInterface::scanDirectory() because it unconditionally
    // excludes anything starting with a dot.
    $finder = Finder::create()
      ->in($this->pathLocator->getProjectRoot())
      ->directories()
      ->name('.git')
      ->ignoreVCS(FALSE)
      ->ignoreDotFiles(FALSE)
      ->ignoreUnreadableDirs();

    foreach ($finder as $git_directory) {
      $project[] = $git_directory->getPathname();
    }

    $this->excludeInWebRoot($event, $web);
    $this->excludeInProjectRoot($event, $project);
  }

  /**
   * Reacts before staged changes are committed the active directory.
   *
   * @param \Drupal\package_manager\Event\PreApplyEvent $event
   *   The event object.
   */
  public function preApply(PreApplyEvent $event): void {
    // Don't copy anything from the staging area's sites/default.
    // @todo Make this a lot smarter in https://www.drupal.org/i/3228955.
    $this->excludeInWebRoot($event, ['sites/default']);

    $this->ignoreCommonPaths($event);
  }

  /**
   * Returns the storage path for a stream wrapper.
   *
   * This will only work for stream wrappers that extend
   * \Drupal\Core\StreamWrapper\LocalStream, which includes the stream wrappers
   * for public and private files.
   *
   * @param string $scheme
   *   The stream wrapper scheme.
   *
   * @return string|null
   *   The storage path for files using the given scheme, relative to the Drupal
   *   root, or NULL if the stream wrapper does not extend
   *   \Drupal\Core\StreamWrapper\LocalStream.
   */
  private function getFilesPath(string $scheme): ?string {
    $wrapper = $this->streamWrapperManager->getViaScheme($scheme);
    if ($wrapper instanceof LocalStream) {
      return $wrapper->getDirectoryPath();
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      PreCreateEvent::class => 'ignoreCommonPaths',
      PreApplyEvent::class => 'preApply',
    ];
  }

}
