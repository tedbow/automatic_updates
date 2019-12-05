<?php

namespace Drupal\automatic_updates;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * DatabaseUpdateHandler plugin manager.
 */
class DatabaseUpdateHandlerPluginManager extends DefaultPluginManager {

  /**
   * Constructs DatabaseUpdateHandlerPluginManager object.
   *
   * @param \Traversable $namespaces
   *   An object that implements \Traversable which contains the root paths
   *   keyed by the corresponding namespace to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to use.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler to invoke the alter hook with.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct(
      'Plugin/DatabaseUpdateHandler',
      $namespaces,
      $module_handler,
      'Drupal\automatic_updates\DatabaseUpdateHandlerInterface',
      'Drupal\automatic_updates\Annotation\DatabaseUpdateHandler'
    );
    $this->alterInfo('database_update_handler_info');
    $this->setCacheBackend($cache_backend, 'database_update_handler_plugins');
  }

}
