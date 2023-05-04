<?php

declare(strict_types = 1);

namespace Drupal\package_manager\PathExcluder;

use Drupal\package_manager\Event\CollectPathsToExcludeEvent;
use Drupal\package_manager\PathLocator;
use PhpTuf\ComposerStager\Infrastructure\Factory\Path\PathFactoryInterface;
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

  use PathExclusionsTrait;

  /**
   * Constructs an ExcludedPathsSubscriber.
   *
   * @param string $sitePath
   *   The current site path, relative to the Drupal root.
   * @param \Drupal\package_manager\PathLocator $path_locator
   *   The path locator service.
   * @param \PhpTuf\ComposerStager\Infrastructure\Factory\Path\PathFactoryInterface $path_factory
   *   The path factory service.
   */
  public function __construct(protected string $sitePath, PathLocator $path_locator, PathFactoryInterface $path_factory) {
    $this->pathLocator = $path_locator;
    $this->pathFactory = $path_factory;
  }

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
    $this->excludeInWebRoot($event, $paths);
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
