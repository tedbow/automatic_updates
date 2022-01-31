<?php

namespace Drupal\automatic_updates_9_3_shim;

use Drupal\Core\Database\Connection;
use Drupal\Core\DrupalKernelInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstaller as CoreModuleInstaller;

/**
 * A module installer which accepts our update registry shim in its constructor.
 */
class ModuleInstaller extends CoreModuleInstaller {

  /**
   * {@inheritdoc}
   */
  public function __construct($root, ModuleHandlerInterface $module_handler, DrupalKernelInterface $kernel, Connection $connection = NULL, UpdateHookRegistry $update_registry = NULL) {
    $this->root = $root;
    $this->moduleHandler = $module_handler;
    $this->kernel = $kernel;
    if (!$connection) {
      @trigger_error('The database connection must be passed to ' . __METHOD__ . '(). Creating ' . __CLASS__ . ' without it is deprecated in drupal:9.2.0 and will be required in drupal:10.0.0. See https://www.drupal.org/node/2970993', E_USER_DEPRECATED);
      $connection = \Drupal::service('database');
    }
    $this->connection = $connection;
    if (!$update_registry) {
      @trigger_error('Calling ' . __METHOD__ . '() without the $update_registry argument is deprecated in drupal:9.3.0 and $update_registry argument will be required in drupal:10.0.0. See https://www.drupal.org/node/2124069', E_USER_DEPRECATED);
      $update_registry = \Drupal::service('update.update_hook_registry');
    }
    $this->updateRegistry = $update_registry;
  }

}
