<?php

namespace Drupal\automatic_updates\Event;

use Drupal\automatic_updates\UpdateMetadata;
use Symfony\Component\EventDispatcher\Event;

/**
 * Defines the post update event.
 *
 * @see \Drupal\automatic_updates\Event\UpdateEvents
 */
class PostUpdateEvent extends Event {

  /**
   * The update metadata.
   *
   * @var \Drupal\automatic_updates\UpdateMetadata
   */
  protected $updateMetadata;

  /**
   * The update success status.
   *
   * @var bool
   */
  protected $success;

  /**
   * Constructs a new PostUpdateEvent.
   *
   * @param \Drupal\automatic_updates\UpdateMetadata $metadata
   *   The update metadata.
   * @param bool $success
   *   TRUE if update succeeded, FALSE otherwise.
   */
  public function __construct(UpdateMetadata $metadata, $success) {
    $this->updateMetadata = $metadata;
    $this->success = $success;
  }

  /**
   * Get the update metadata.
   *
   * @return \Drupal\automatic_updates\UpdateMetadata
   *   The update metadata.
   */
  public function getUpdateMetadata() {
    return $this->updateMetadata;
  }

  /**
   * Gets the update success status.
   *
   * @return bool
   *   TRUE if update succeeded, FALSE otherwise.
   */
  public function success() {
    return $this->success;
  }

}
