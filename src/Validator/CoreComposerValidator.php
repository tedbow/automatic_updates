<?php

namespace Drupal\automatic_updates\Validator;

use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\package_manager\ValidationResult;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates the Drupal core requirements defined in composer.json.
 */
class CoreComposerValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Validates the Drupal core requirements in composer.json.
   *
   * @param \Drupal\automatic_updates\Event\ReadinessCheckEvent $event
   *   The event object.
   */
  public function checkCoreRequirements(ReadinessCheckEvent $event): void {
    // Ensure that either drupal/core or drupal/core-recommended is required.
    // If neither is, then core cannot be updated, which we consider an error
    // condition.
    $core_requirements = array_intersect(
      $event->getActiveComposer()->getCorePackageNames(),
      ['drupal/core', 'drupal/core-recommended']
    );
    if (empty($core_requirements)) {
      $error = ValidationResult::createError([
        $this->t('Drupal core does not appear to be required in the project-level composer.json.'),
      ]);
      $event->addValidationResult($error);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      ReadinessCheckEvent::class => ['checkCoreRequirements', 1000],
    ];
  }

}
