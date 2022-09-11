<?php

/**
 * @file
 */

$projects_dir = __DIR__ . '/../../web/projects';
return [
  'versions' => [
    'drupal/package_project_match' => [
      'type' => 'drupal-module',
      'install_path' => $projects_dir . '/package_project_match',
    ],
    'drupal/not_match_package' => [
      'type' => 'drupal-module',
      'install_path' => $projects_dir . '/not_match_project',
    ],
    'drupal/not_match_path_project' => [
      'type' => 'drupal-module',
      'install_path' => $projects_dir . '/not_match_project',
    ],
    'drupal/nested_no_match_package' => [
      'type' => 'drupal-module',
      'install_path' => $projects_dir . '/any_folder_name',
    ],
    'non_drupal/other_project' => [
      'type' => 'drupal-module',
      'install_path' => $projects_dir . '/other_project',
    ],
    'drupal/custom_module' => [
      'type' => 'drupal-custom-module',
      'install_path' => $projects_dir . '/custom_module',
    ],
  ],
];
