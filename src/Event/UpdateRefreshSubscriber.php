<?php

namespace Drupal\automatic_updates\Event;

use Drupal\package_manager\Event\PostApplyEvent;
use Drupal\update\UpdateManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Clears stale update data once a staged update has been committed.
 */
class UpdateRefreshSubscriber implements EventSubscriberInterface {

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
