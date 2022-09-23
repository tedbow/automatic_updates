<?php

/**
 * @file
 * Lists packages installed by Composer.
 */

$projects_dir = __DIR__ . '/../../modules';
return [
  'versions' => [
    'drupal/aaa_update_test' => [
      'type' => 'drupal-module',
      'install_path' => $projects_dir . '/aaa_update_test',
    ],
  ],
];
