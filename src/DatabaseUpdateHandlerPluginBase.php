<?php

namespace Drupal\automatic_updates;

use Drupal\Component\Plugin\PluginBase;

/**
 * Base class for database_update_handler plugins.
 */
abstract class DatabaseUpdateHandlerPluginBase extends PluginBase implements DatabaseUpdateHandlerInterface {

  /**
   * {@inheritdoc}
   */
  public function label() {
    return $this->pluginDefinition['label'];
  }

}
