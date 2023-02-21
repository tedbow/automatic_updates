<?php

declare(strict_types = 1);

namespace Drupal\package_manager\Validator;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;
use Drupal\package_manager\Event\StatusCheckEvent;
use Drupal\package_manager\PathLocator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates staging root is not a subdirectory of active.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
class StageNotInActiveValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Constructs a new StageNotInActiveValidator object.
   *
   * @param \Drupal\package_manager\PathLocator $pathLocator
   *   The path locator service.
   */
  public function __construct(protected PathLocator $pathLocator) {
  }

  /**
   * Check if staging root is a subdirectory of active.
   */
  public function validate(PreOperationStageEvent $event) {
    $project_root = $this->pathLocator->getProjectRoot();
    $staging_root = $this->pathLocator->getStagingRoot();
    if (str_starts_with($staging_root, $project_root)) {
      $message = $this->t("Stage directory is a subdirectory of the active directory.");
      $event->addError([$message]);
      $event->stopPropagation();
    }

  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PreCreateEvent::class => 'validate',
      StatusCheckEvent::class => 'validate',
    ];
  }

}
