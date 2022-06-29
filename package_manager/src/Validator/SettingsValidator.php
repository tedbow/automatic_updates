<?php

namespace Drupal\package_manager\Validator;

use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;

/**
 * Checks that Drupal's settings are valid for Package Manager.
 */
class SettingsValidator implements PreOperationStageValidatorInterface {

  use StringTranslationTrait;

  /**
   * Constructs a SettingsValidator object.
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
    if (Settings::get('update_fetch_with_http_fallback')) {
      $event->addError([
        $this->t('The <code>update_fetch_with_http_fallback</code> setting must be disabled.'),
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
