<?php

/**
 * @file
 * Simulates several modules installed by Composer.
 *
 * @see \Drupal\Tests\package_manager\Kernel\OverwriteExistingPackagesValidatorTest::testNewPackagesOverwriteExisting()
 */

$modules_dir = __DIR__ . '/../../modules';

return [
  'versions' => [
    'drupal/module_1' => [
      'type' => 'drupal-module',
      'install_path' => $modules_dir . '/module_1',
    ],
    'drupal/module_2' => [
      'type' => 'drupal-module',
      'install_path' => $modules_dir . '/module_2',
    ],
    'drupal/module_3' => [
      'type' => 'drupal-module',
      'install_path' => $modules_dir . '/module_3',
    ],
    'drupal/module_4' => [
      'type' => 'drupal-module',
      'install_path' => $modules_dir . '/module_1',
    ],
    'drupal/module_5' => [
      'type' => 'drupal-module',
      'install_path' => $modules_dir . '/module_5_different_path',
    ],
  ],
];
