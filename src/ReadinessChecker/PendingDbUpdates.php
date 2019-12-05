<?php

namespace Drupal\automatic_updates\ReadinessChecker;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Update\UpdateRegistry;

/**
 * Pending database updates checker.
 */
class PendingDbUpdates implements ReadinessCheckerInterface {
  use StringTranslationTrait;

  /**
   * The update registry.
   *
   * @var \Drupal\Core\Update\UpdateRegistry
   */
  protected $updateRegistry;

  /**
   * PendingDbUpdates constructor.
   *
   * @param \Drupal\Core\Update\UpdateRegistry $update_registry
   *   The update registry.
   */
  public function __construct(UpdateRegistry $update_registry) {
    $this->updateRegistry = $update_registry;
  }

  /**
   * {@inheritdoc}
   */
  public function run() {
    $messages = [];

    if ($this->areDbUpdatesPending()) {
      $messages[] = $this->t('There are pending database updates. Please run update.php.');
    }
    return $messages;
  }

  /**
   * Checks if there are pending database updates.
   *
   * @return bool
   *   TRUE if there are pending updates, otherwise FALSE.
   */
  protected function areDbUpdatesPending() {
    require_once DRUPAL_ROOT . '/core/includes/install.inc';
    require_once DRUPAL_ROOT . '/core/includes/update.inc';
    drupal_load_updates();
    $hook_updates = update_get_update_list();
    $post_updates = $this->updateRegistry->getPendingUpdateFunctions();
    return (bool) array_merge($hook_updates, $post_updates);
  }

}
