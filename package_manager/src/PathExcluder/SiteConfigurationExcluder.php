<?php

namespace Drupal\package_manager\PathExcluder;

use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\StageEvent;
use Drupal\package_manager\PathLocator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Excludes site configuration files from staging areas.
 */
class SiteConfigurationExcluder implements EventSubscriberInterface {

  use PathExclusionsTrait;

  /**
   * The current site path, relative to the Drupal root.
   *
   * @var string
   */
  protected $sitePath;

  /**
   * Constructs an ExcludedPathsSubscriber.
   *
   * @param string $site_path
   *   The current site path, relative to the Drupal root.
   * @param \Drupal\package_manager\PathLocator $path_locator
   *   The path locator service.
   */
  public function __construct(string $site_path, PathLocator $path_locator) {
    $this->sitePath = $site_path;
    $this->pathLocator = $path_locator;
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
    // Site configuration files are always excluded relative to the web root.
    $web = [];

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

    $this->excludeInWebRoot($event, $web);
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
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      PreCreateEvent::class => 'ignoreCommonPaths',
      PreApplyEvent::class => 'preApply',
    ];
  }

}
