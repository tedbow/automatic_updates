<?php

declare(strict_types = 1);

namespace Drupal\automatic_updates\Validator;

use Drupal\automatic_updates\CronUpdater;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Validator\StagedDBUpdateValidator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates that there are no database updates in a staged update.
 *
 * @internal
 *   This is an internal part of Automatic Updates and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
class StagedDatabaseUpdateValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Constructs a StagedDatabaseUpdateValidator object.
   *
   * @param \Drupal\package_manager\Validator\StagedDBUpdateValidator $stagedDBUpdateValidator
   *   The Staged DB Update Validator service.
   */
  public function __construct(protected StagedDBUpdateValidator $stagedDBUpdateValidator) {
  }

  /**
   * Checks that the staged update does not have changes to its install files.
   *
   * @param \Drupal\package_manager\Event\PreApplyEvent $event
   *   The event object.
   */
  public function checkUpdateHooks(PreApplyEvent $event): void {
    $stage = $event->stage;
    if (!$stage instanceof CronUpdater) {
      return;
    }

    $invalid_extensions = $this->stagedDBUpdateValidator->getExtensionsWithDatabaseUpdates($stage->getStageDirectory());
    if ($invalid_extensions) {
      $invalid_extensions = array_map($this->t(...), $invalid_extensions);
      $event->addError($invalid_extensions, $this->t('The update cannot proceed because possible database updates have been detected in the following extensions.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PreApplyEvent::class => 'checkUpdateHooks',
    ];
  }

}
