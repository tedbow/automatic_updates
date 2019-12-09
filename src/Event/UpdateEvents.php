<?php

namespace Drupal\automatic_updates\Event;

/**
 * Defines events for the automatic_updates module.
 */
final class UpdateEvents {

  /**
   * Name of the event fired after updating a site.
   *
   * @Event
   *
   * @see \Drupal\automatic_updates\Event\PostUpdateEvent
   */
  const POST_UPDATE = 'automatic_updates.post_update';

}
