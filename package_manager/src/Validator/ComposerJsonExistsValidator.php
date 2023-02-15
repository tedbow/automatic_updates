<?php

declare(strict_types = 1);

namespace Drupal\package_manager\Validator;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\package_manager\Event\PreApplyEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;
use Drupal\package_manager\Event\StatusCheckEvent;
use Drupal\package_manager\PathLocator;

/**
 * Validates that the active composer.json file exists.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
final class ComposerJsonExistsValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Constructs a ComposerJsonExistsValidator object.
   *
   * @param \Drupal\package_manager\PathLocator $pathLocator
   *   The path locator service.
   */
  public function __construct(protected PathLocator $pathLocator) {
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Set priority to 190 which puts it just after EnvironmentSupportValidator.
    // @see \Drupal\package_manager\Validator\EnvironmentSupportValidator
    return [
      PreCreateEvent::class => ['validateComposerJson', 190],
      PreApplyEvent::class => ['validateComposerJson', 190],
      StatusCheckEvent::class => ['validateComposerJson', 190],
    ];
  }

  /**
   * Validates that the active composer.json file exists.
   *
   * @param \Drupal\package_manager\Event\PreOperationStageEvent $event
   *   The event.
   */
  public function validateComposerJson(PreOperationStageEvent $event): void {
    $project_root = $this->pathLocator->getProjectRoot();
    if (!file_exists($project_root . '/composer.json')) {
      $event->addError([$this->t('No composer.json file can be found at @project_root', ['@project_root' => $project_root])]);
      $event->stopPropagation();
    }
  }

}
