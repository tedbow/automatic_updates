<?php

namespace Drupal\package_manager\PathExcluder;

use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\StageEvent;
use Drupal\package_manager\PathLocator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Excludes 'sites/simpletest' from staging operations.
 */
class TestSiteExcluder implements EventSubscriberInterface {

  use PathExclusionsTrait;

  /**
   * Constructs a TestSiteExcluder object.
   *
   * @param \Drupal\package_manager\PathLocator $path_locator
   *   The path locator service.
   */
  public function __construct(PathLocator $path_locator) {
    $this->pathLocator = $path_locator;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      PreCreateEvent::class => 'excludeTestSites',
      PreApplyEvent::class => 'excludeTestSites',
    ];
  }

  /**
   * Excludes sites/simpletest from staging operations.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   */
  public function excludeTestSites(StageEvent $event): void {
    // Always ignore automated test directories. If they exist, they will be in
    // the web root.
    $this->excludeInWebRoot($event, ['sites/simpletest']);
  }

}
