<?php

/**
 * @file
 * Lists packages installed by Composer.
 */

$projects_dir = __DIR__ . '/../../modules';
return [
  'versions' => [
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
