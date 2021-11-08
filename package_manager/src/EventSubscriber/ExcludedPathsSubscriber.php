<?php

namespace Drupal\package_manager\EventSubscriber;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\LocalStream;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Defines an event subscriber to exclude certain paths from staging areas.
 */
class ExcludedPathsSubscriber implements EventSubscriberInterface {

  /**
   * The Drupal root.
   *
   * @var string
   */
  protected $appRoot;

  /**
   * The current site path, relative to the Drupal root.
   *
   * @var string
   */
  protected $sitePath;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The stream wrapper manager service.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected $streamWrapperManager;

  /**
   * Constructs an UpdateSubscriber.
   *
   * @param string $app_root
   *   The Drupal root.
   * @param string $site_path
   *   The current site path, relative to the Drupal root.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $stream_wrapper_manager
   *   The stream wrapper manager service.
   */
  public function __construct(string $app_root, string $site_path, FileSystemInterface $file_system, StreamWrapperManagerInterface $stream_wrapper_manager) {
    $this->appRoot = $app_root;
    $this->sitePath = $site_path;
    $this->fileSystem = $file_system;
    $this->streamWrapperManager = $stream_wrapper_manager;
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
    $event->excludePath('sites/default');

    // If the core-vendor-hardening plugin (used in the legacy-project template)
    // is present, it may have written a web.config file into the vendor
    // directory. We don't want to copy that.
    $event->excludePath('web.config');
  }

  /**
   * Excludes paths from a staging area before it is created.
   *
   * @param \Drupal\package_manager\Event\PreCreateEvent $event
   *   The event object.
   */
  public function preCreate(PreCreateEvent $event): void {
    // Automated test site directories should never be staged.
    $event->excludePath('sites/simpletest');

    // Windows server configuration files, like web.config, should never be
    // staged either. (These can be written in the vendor directory by the
    // core-vendor-hardening plugin, which is used in the drupal/legacy-project
    // template.)
    $event->excludePath('web.config');

    if ($public = $this->getFilesPath('public')) {
      $event->excludePath($public);
    }
    if ($private = $this->getFilesPath('private')) {
      $event->excludePath($private);
    }

    // Exclude site-specific settings files.
    $settings_files = [
      'settings.php',
      'settings.local.php',
      'services.yml',
    ];
    $default_site = 'sites' . DIRECTORY_SEPARATOR . 'default';

    foreach ($settings_files as $settings_file) {
      $event->excludePath($this->sitePath . DIRECTORY_SEPARATOR . $settings_file);
      $event->excludePath($default_site . DIRECTORY_SEPARATOR . $settings_file);
    }
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
      PreCreateEvent::class => 'preCreate',
      PreApplyEvent::class => 'preApply',
    ];
  }

}
