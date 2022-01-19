<?php

namespace Drupal\package_manager\Validator;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;

/**
 * Validates certain Composer settings.
 */
class ComposerSettingsValidator implements PreOperationStageValidatorInterface {

  use StringTranslationTrait;

  /**
   * Constructs a ComposerSettingsValidator object.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The string translation service.
   */
  public function __construct(TranslationInterface $translation) {
    $this->setStringTranslation($translation);
  }

  /**
   * {@inheritdoc}
   */
  public function validateStagePreOperation(PreOperationStageEvent $event): void {
    $config = $event->getStage()
      ->getActiveComposer()
      ->getComposer()
      ->getConfig();

    if ($config->get('secure-http') !== TRUE) {
      $event->addError([
        $this->t('HTTPS must be enabled for Composer downloads. See <a href=":url">the Composer documentation</a> for more information.', [
          ':url' => 'https://getcomposer.org/doc/06-config.md#secure-http',
        ]),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      PreCreateEvent::class => 'validateStagePreOperation',
    ];
  }

}