<?php

namespace Drupal\automatic_updates\Validator;

use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\automatic_updates\Updater;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * @internal
 *   This class is an internal part of the module's update handling and
 *   should not be used by external code.
 */
class SettingsValidator implements EventSubscriberInterface {

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
   * Validates site settings before an update starts.
   *
   * @param \Drupal\package_manager\Event\PreOperationStageEvent $event
   *   The event object.
   */
  public function checkSettings(PreOperationStageEvent $event): void {
    if ($event->getStage() instanceof Updater && Settings::get('update_fetch_with_http_fallback')) {
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
      ReadinessCheckEvent::class => 'checkSettings',
      PreCreateEvent::class => 'checkSettings',
    ];
  }

}
