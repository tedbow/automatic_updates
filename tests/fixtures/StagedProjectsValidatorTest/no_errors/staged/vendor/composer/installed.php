<?php

/**
 * @file
 * Simulates that 2 packages are installed in virtual staging area.
 */

$projects_dir = __DIR__ . '/../../';
return [
  'versions' => [
    'other/new_project' => [
      'type' => 'library',
      'install_path' => $projects_dir . '/other/new_project',
    ],
    'other/dev-new_project' => [
      'type' => 'library',
      'install_path' => $projects_dir . '/other/dev-new_project',
    ],
  ],
];
