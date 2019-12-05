<?php

namespace Drupal\automatic_updates\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Defines a DatabaseUpdateHandler annotation object.
 *
 * Plugin Namespace: Plugin\DatabaseUpdateHandler.
 *
 * For a working example, see
 * \Drupal\automatic_updates\Plugin\DatabaseUpdateHandler\MaintenanceMode.
 *
 * @see \Drupal\automatic_updates\DatabaseUpdateHandlerInterface
 * @see \Drupal\automatic_updates\DatabaseUpdateHandlerPluginBase
 * @see \Drupal\automatic_updates\DatabaseUpdateHandlerPluginManager
 * @see hook_database_update_handler_plugin_info_alter()
 * @see plugin_api
 *
 * @Annotation
 */
final class DatabaseUpdateHandler extends Plugin {

  /**
   * The ID of the handler, should match the service name.
   *
   * @var string
   */
  public $id;

  /**
   * The name of the handler.
   *
   * @var string
   */
  public $label;

}
