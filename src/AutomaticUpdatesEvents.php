<?php

namespace Drupal\automatic_updates;

/**
 * Defines events for the automatic_updates module.
 *
 * These events allow listeners to validate updates at various points in the
 * update process.  Listeners to these events should add validation results via
 * \Drupal\automatic_updates\Event\UpdateEvent::addValidationResult() if
 * necessary. Only error level validation results will stop an update from
 * continuing.
 *
 * @see \Drupal\automatic_updates\Event\UpdateEvent
 * @see \Drupal\automatic_updates\Validation\ValidationResult
 */
final class AutomaticUpdatesEvents {

  /**
   * Name of the event fired when checking if the site could perform an update.
   *
   * An update is not actually being started when this event is being fired. It
   * should be used to notify site admins if the site is in a state which will
   * not allow automatic updates to succeed.
   *
   * This event should only be dispatched from ReadinessValidationManager to
   * allow caching of the results.
   *
   * @Event
   *
   * @see \Drupal\automatic_updates\Validation\ReadinessValidationManager
   *
   * @var string
   */
  const READINESS_CHECK = 'automatic_updates.readiness_check';

  /**
   * Name of the event fired when an automatic update is starting.
   *
   * This event is fired before any files are staged. Validation results added
   * by subscribers are not cached.
   *
   * @Event
   *
   * @var string
   */
  const PRE_START = 'automatic_updates.pre_start';

  /**
   * Name of the event fired when an automatic update is about to be committed.
   *
   * Validation results added by subscribers are not cached.
   *
   * @Event
   *
   * @var string
   */
  const PRE_COMMIT = 'automatic_updates.pre_commit';

  /**
   * Name of the event fired when a staged update has been committed.
   *
   * @Event
   *
   * @var string
   */
  const POST_COMMIT = 'automatic_updates.post_commit';

}
