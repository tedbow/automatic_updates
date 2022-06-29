<?php

namespace Drupal\automatic_updates_extensions_test;

use Drupal\automatic_updates_extensions\ExtensionUpdater;
use Drupal\package_manager\ComposerUtility;

/**
 * Extends the updater to point to a fixture directory for the active Composer.
 */
class TestExtensionUpdater extends ExtensionUpdater {

  /**
   * {@inheritdoc}
   */
  public function getActiveComposer(): ComposerUtility {
    if ($path = \Drupal::state()->get('automatic_updates_extensions_test.active_path')) {
      return ComposerUtility::createForDirectory($path);
    }
    return parent::getActiveComposer();
  }

}
