<?php

declare(strict_types = 1);

namespace Drupal\package_manager_test_validation;

use Drupal\package_manager\ComposerInspector;
use Drupal\package_manager\Event\CollectIgnoredPathsEvent;
use Drupal\package_manager\PathLocator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Allows to test an excluder which fails on CollectIgnoredPathsEvent.
 */
class CollectIgnoredPathsFailValidator implements EventSubscriberInterface {

  /**
   * Constructs a CollectIgnoredPathsFailValidator object.
   *
   * @param \Drupal\package_manager\ComposerInspector $composerInspector
   *   The Composer inspector service.
   * @param \Drupal\package_manager\PathLocator $pathLocator
   *   The path locator service.
   */
  public function __construct(private ComposerInspector $composerInspector, private PathLocator $pathLocator) {
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      CollectIgnoredPathsEvent::class => 'callToComposer',
    ];
  }

  /**
   * Fails when composer.json is deleted to simulate failure on excluders.
   */
  public function callToComposer() {
    $this->composerInspector->validate($this->pathLocator->getProjectRoot());
  }

}
