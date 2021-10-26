<?php

namespace Drupal\automatic_updates\Event;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\LocalStream;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Defines an event subscriber to exclude certain paths from update operations.
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
   * The module list service.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected $moduleList;

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
   * @param \Drupal\Core\Extension\ModuleExtensionList $module_list
   *   The module list service.
   */
  public function __construct(string $app_root, string $site_path, FileSystemInterface $file_system, StreamWrapperManagerInterface $stream_wrapper_manager, ModuleExtensionList $module_list) {
    $this->appRoot = $app_root;
    $this->sitePath = $site_path;
    $this->fileSystem = $file_system;
    $this->streamWrapperManager = $stream_wrapper_manager;
    $this->moduleList = $module_list;
  }

  /**
   * Reacts before staged updates are committed the active directory.
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
   * Reacts to the beginning of an update process.
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
    // If this module is a git clone, exclude it.
    if (is_dir(__DIR__ . '/../../.git')) {
      $dir = $this->moduleList->getPath('automatic_updates');
      $event->excludePath($dir);
    }

    // Exclude site-specific settings files.
    $settings_files = [
      'settings.php',
      'settings.local.php',
      'services.yml',
    ];
    foreach ($settings_files as $settings_file) {
      $file_path = implode(DIRECTORY_SEPARATOR, [
        $this->appRoot,
        $this->sitePath,
        $settings_file,
      ]);
      $file_path = $this->fileSystem->realpath($file_path);
      if (file_exists($file_path)) {
        $event->excludePath($file_path);
      }

      $default_file_path = implode(DIRECTORY_SEPARATOR, [
        'sites',
        'default',
        $settings_file,
      ]);
      $event->excludePath($default_file_path);
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
