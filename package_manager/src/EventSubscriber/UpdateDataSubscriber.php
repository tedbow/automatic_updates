<?php

namespace Drupal\package_manager\EventSubscriber;

use Drupal\package_manager\Event\PostApplyEvent;
use Drupal\update\UpdateManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Clears stale update data once staged changes have been applied.
 */
class UpdateDataSubscriber implements EventSubscriberInterface {

  /**
   * The update manager service.
   *
   * @var \Drupal\update\UpdateManagerInterface
   */
  protected $updateManager;

  /**
   * Constructs an UpdateRefreshSubscriber object.
   *
   * @param \Drupal\update\UpdateManagerInterface $update_manager
   *   The update manager service.
   */
  public function __construct(UpdateManagerInterface $update_manager) {
    $this->updateManager = $update_manager;
  }

  /**
   * Clears stale update data.
   *
   * This will always run after any staging area is applied to the active
   * directory, since it's likely that core and/or multiple extensions have been
   * added, removed, or updated.
   */
  public function clearData(): void {
    $this->updateManager->refreshUpdateData();
    update_storage_clear();
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      PostApplyEvent::class => ['clearData', 1000],
    ];
  }

}
