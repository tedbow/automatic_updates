<?php

declare(strict_types = 1);

namespace Drupal\package_manager\Event;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\package_manager\StageBase;
use Drupal\package_manager\ValidationResult;
use Drupal\system\SystemManager;

/**
 * Event fired to check the status of the system to use Package Manager.
 *
 * The event's stage will be set with the type of stage that will perform the
 * operations. The stage may or may not be currently in use.
 */
class StatusCheckEvent extends PreOperationStageEvent {

  /**
   * Returns paths to exclude or NULL if a base requirement is not fulfilled.
   *
   * @return string[]|null
   *   The paths to exclude, or NULL if a base requirement is not fulfilled.
   *
   * @throws \LogicException
   *   Thrown if the excluded paths are NULL and no errors have been added to
   *   this event.
   */
  public function getExcludedPaths(): ?array {
    if (isset($this->excludedPaths)) {
      return array_unique($this->excludedPaths);
    }

    if (empty($this->getResults(SystemManager::REQUIREMENT_ERROR))) {
      throw new \LogicException('$ignored_paths should only be NULL if the error that caused the paths to not be collected was added to the status check event.');
    }
    return NULL;
  }

  /**
   * Constructs a StatusCheckEvent object.
   *
   * @param \Drupal\package_manager\StageBase $stage
   *   The stage which fired this event.
   * @param string[]|null $excludedPaths
   *   The list of ignored paths, or NULL if they could not be collected.
   */
  public function __construct(StageBase $stage, private ?array $excludedPaths) {
    parent::__construct($stage);
  }

  /**
   * Adds warning information to the event.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup[] $messages
   *   One or more warning messages.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $summary
   *   A summary of warning messages. Required if there is more than one
   *   message, optional otherwise.
   */
  public function addWarning(array $messages, ?TranslatableMarkup $summary = NULL): void {
    $this->results[] = ValidationResult::createWarning($messages, $summary);
  }

}
