<?php

/**
 * @file
 */

$projects_dir = __DIR__ . '/../../web/projects';
return [
  'versions' => [
    'drupal/automatic_updates_extensions_test_theme' => [
      'type' => 'drupal-theme',
      'install_path' => $projects_dir . '/automatic_updates_extensions_test_theme',
    ],
    'drupal/semver_test' => [
      'type' => 'drupal-module',
      'install_path' => $projects_dir . '/semver_test',
    ],
    'drupal/aaa_update_test' => [
      'type' => 'drupal-module',
      'install_path' => $projects_dir . '/aaa_update_test',
    ],
  ],
];
