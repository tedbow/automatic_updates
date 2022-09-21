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
    'drupal/test_module2' => [
      'type' => 'drupal-module',
      'install_path' => $projects_dir . '/test_module2',
    ],
    'drupal/dev-test_module2' => [
      'type' => 'drupal-module',
      'install_path' => $projects_dir . '/dev-test_module2',
    ],
    'other/new_project' => [
      'type' => 'library',
      'install_path' => __DIR__ . '/../../new_project',
    ],
    'other/dev-new_project' => [
      'type' => 'library',
      'install_path' => __DIR__ . '/../../dev-new_project',
    ],
  ],
];
