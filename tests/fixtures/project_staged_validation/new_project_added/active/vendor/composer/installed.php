<?php

/**
 * @file
 */

$projects_dir = __DIR__ . '/../../modules';
return [
  'versions' => [
    'drupal/test_module' => [
      'type' => 'drupal-module',
      'install_path' => $projects_dir . '/test_module',
    ],
    'drupal/dev-test_module' => [
      'type' => 'drupal-module',
      'install_path' => $projects_dir . '/dev-test_module',
    ],
  ],
];
