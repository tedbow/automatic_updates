<?php

namespace Drupal\automatic_updates\Validator;

use Drupal\automatic_updates\Event\PreStartEvent;
use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\automatic_updates\Event\UpdateEvent;
use Drupal\package_manager\ValidationResult;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Update\UpdateRegistry;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates that there are no pending database updates.
 */
class PendingUpdatesValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The Drupal root.
   *
   * @var string
   */
  protected $appRoot;

  /**
   * The update registry service.
   *
   * @var \Drupal\Core\Update\UpdateRegistry
   */
  protected $updateRegistry;

  /**
   * Constructs an PendingUpdatesValidator object.
   *
   * @param string $app_root
   *   The Drupal root.
   * @param \Drupal\Core\Update\UpdateRegistry $update_registry
   *   The update registry service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The translation service.
   */
  public function __construct(string $app_root, UpdateRegistry $update_registry, TranslationInterface $translation) {
    $this->appRoot = $app_root;
    $this->updateRegistry = $update_registry;
    $this->setStringTranslation($translation);
  }

  /**
   * Validates that there are no pending database updates.
   *
   * @param \Drupal\automatic_updates\Event\UpdateEvent $event
   *   The update event.
   */
  public function checkPendingUpdates(UpdateEvent $event) {
    require_once $this->appRoot . '/core/includes/install.inc';
    require_once $this->appRoot . '/core/includes/update.inc';

    drupal_load_updates();
    $hook_updates = update_get_update_list();
    $post_updates = $this->updateRegistry->getPendingUpdateFunctions();

    if ($hook_updates || $post_updates) {
      $message = $this->t('Some modules have database schema updates to install. You should run the <a href=":update">database update script</a> immediately.', [
        ':update' => Url::fromRoute('system.db_update')->toString(),
      ]);
      $error = ValidationResult::createError([$message]);
      $event->addValidationResult($error);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      PreStartEvent::class => 'checkPendingUpdates',
      ReadinessCheckEvent::class => 'checkPendingUpdates',
    ];
  }

}
