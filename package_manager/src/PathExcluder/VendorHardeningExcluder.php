<?php

namespace Drupal\package_manager\PathExcluder;

use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\StageEvent;
use Drupal\package_manager\PathLocator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Excludes vendor hardening files from staging operations.
 */
class VendorHardeningExcluder implements EventSubscriberInterface {

  use PathExclusionsTrait;

  /**
   * Constructs a VendorHardeningExcluder object.
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
      PreCreateEvent::class => 'excludeVendorHardeningFiles',
      PreApplyEvent::class => 'excludeVendorHardeningFiles',
    ];
  }

  /**
   * Excludes vendor hardening files from staging operations.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   */
  public function excludeVendorHardeningFiles(StageEvent $event): void {
    // If the core-vendor-hardening plugin (used in the legacy-project template)
    // is present, it may have written security hardening files in the vendor
    // directory. They should always be ignored.
    $vendor_dir = $this->pathLocator->getVendorDirectory();
    $this->excludeInProjectRoot($event, [
      $vendor_dir . '/web.config',
      $vendor_dir . '/.htaccess',
    ]);
  }

}