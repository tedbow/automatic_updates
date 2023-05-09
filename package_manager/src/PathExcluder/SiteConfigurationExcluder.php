<?php

declare(strict_types = 1);

namespace Drupal\package_manager\PathExcluder;

use Drupal\package_manager\Event\CollectPathsToExcludeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Excludes site configuration files from stage directories.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
class SiteConfigurationExcluder implements EventSubscriberInterface {

  /**
   * Constructs an SiteConfigurationExcluder.
   *
   * @param string $sitePath
   *   The current site path, relative to the Drupal root.
   */
  public function __construct(protected string $sitePath) {}

  /**
   * Excludes site configuration files from stage operations.
   *
   * @param \Drupal\package_manager\Event\CollectPathsToExcludeEvent $event
   *   The event object.
   */
  public function excludeSiteConfiguration(CollectPathsToExcludeEvent $event): void {
    // Site configuration files are always excluded relative to the web root.
    $paths = [];

    // Exclude site-specific settings files, which are always in the web root.
    // By default, Drupal core will always try to write-protect these files.
    $settings_files = [
      'settings.php',
      'settings.local.php',
      'services.yml',
    ];
    foreach ($settings_files as $settings_file) {
      $paths[] = $this->sitePath . '/' . $settings_file;
      $paths[] = 'sites/default/' . $settings_file;
    }
    $event->addPathsRelativeToWebRoot($paths);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      CollectPathsToExcludeEvent::class => 'excludeSiteConfiguration',
    ];
  }

}
