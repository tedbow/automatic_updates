<?php

/**
 * @file
 * Lists packages installed by Composer.
 */

$projects_dir = __DIR__ . '/../../modules';
return [
  'versions' => [
    'drupal/aaa_automatic_updates_test' => [
      'type' => 'drupal-module',
      'install_path' => $projects_dir . '/aaa_automatic_updates_test',
    ],
  ],
];
