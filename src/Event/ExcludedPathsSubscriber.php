<?php

namespace Drupal\automatic_updates\Event;

use Drupal\automatic_updates\AutomaticUpdatesEvents;
use Drupal\Core\File\FileSystemInterface;
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
   * Constructs an UpdateSubscriber.
   *
   * @param string $app_root
   *   The Drupal root.
   * @param string $site_path
   *   The current site path, relative to the Drupal root.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   */
  public function __construct(string $app_root, string $site_path, FileSystemInterface $file_system) {
    $this->appRoot = $app_root;
    $this->sitePath = $site_path;
    $this->fileSystem = $file_system;
  }

  /**
   * Reacts to the beginning of an update process.
   *
   * @param \Drupal\automatic_updates\Event\PreStartEvent $event
   *   The event object.
   */
  public function preStart(PreStartEvent $event): void {
    if ($public = $this->fileSystem->realpath('public://')) {
      $event->excludePath($public);
    }
    if ($private = $this->fileSystem->realpath('private://')) {
      $event->excludePath($private);
    }
    // If this module is a git clone, exclude it.
    if (is_dir(__DIR__ . '/../../.git')) {
      $event->excludePath($this->fileSystem->realpath(__DIR__ . '/../..'));
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
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      AutomaticUpdatesEvents::PRE_START => 'preStart',
    ];
  }

}
