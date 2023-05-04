<?php

declare(strict_types = 1);

namespace Drupal\package_manager\PathExcluder;

use Drupal\package_manager\Event\CollectPathsToExcludeEvent;
use Drupal\package_manager\PathLocator;
use PhpTuf\ComposerStager\Infrastructure\Factory\Path\PathFactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Excludes node_modules files from stage directories.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
class NodeModulesExcluder implements EventSubscriberInterface {

  use PathExclusionsTrait;

  /**
   * Constructs a NodeModulesExcluder object.
   *
   * @param \Drupal\package_manager\PathLocator $path_locator
   *   The path locator service.
   * @param \PhpTuf\ComposerStager\Infrastructure\Factory\Path\PathFactoryInterface $path_factory
   *   The path factory service.
   */
  public function __construct(PathLocator $path_locator, PathFactoryInterface $path_factory) {
    $this->pathLocator = $path_locator;
    $this->pathFactory = $path_factory;
  }

  /**
   * Excludes node_modules directories from stage operations.
   *
   * @param \Drupal\package_manager\Event\CollectPathsToExcludeEvent $event
   *   The event object.
   */
  public function excludeNodeModulesFiles(CollectPathsToExcludeEvent $event): void {
    $paths = $this->scanForDirectoriesByName('node_modules');
    $this->excludeInProjectRoot($event, $paths);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      CollectPathsToExcludeEvent::class => 'excludeNodeModulesFiles',
    ];
  }

}
