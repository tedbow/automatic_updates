<?php

namespace Drupal\automatic_updates\ReadinessChecker;

use Drupal\automatic_updates\IgnoredPathsIteratorFilter;
use Drupal\automatic_updates\ProjectInfoTrait;
use Drupal\automatic_updates\Services\ModifiedFilesInterface;
use Drupal\Core\Extension\ExtensionList;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Modified code checker.
 */
class ModifiedFiles implements ReadinessCheckerInterface {
  use StringTranslationTrait;
  use ProjectInfoTrait;

  /**
   * The modified files service.
   *
   * @var \Drupal\automatic_updates\Services\ModifiedFilesInterface
   */
  protected $modifiedFiles;

  /**
   * An array of array of strings of extension paths.
   *
   * @var string[]string[]
   */
  protected $paths;

  /**
   * ModifiedFiles constructor.
   *
   * @param \Drupal\automatic_updates\Services\ModifiedFilesInterface $modified_files
   *   The modified files service.
   *   The config factory.
   * @param \Drupal\Core\Extension\ExtensionList $modules
   *   The module extension list.
   * @param \Drupal\Core\Extension\ExtensionList $profiles
   *   The profile extension list.
   * @param \Drupal\Core\Extension\ExtensionList $themes
   *   The theme extension list.
   */
  public function __construct(ModifiedFilesInterface $modified_files, ExtensionList $modules, ExtensionList $profiles, ExtensionList $themes) {
    $this->modifiedFiles = $modified_files;
    $this->setExtensionLists($modules, $themes, $profiles);
  }

  /**
   * {@inheritdoc}
   */
  public function run() {
    return $this->modifiedFilesCheck();
  }

  /**
   * Check if the site contains any modified code.
   *
   * @return array
   *   An array of translatable strings if any checks fail.
   */
  protected function modifiedFilesCheck() {
    $messages = [];
    $extensions = [];
    foreach ($this->getExtensionsTypes() as $extension_type) {
      $extensions[] = $this->getInfos($extension_type);
    }
    $extensions = array_merge(...$extensions);
    $filtered_modified_files = new IgnoredPathsIteratorFilter($this->modifiedFiles->getModifiedFiles($extensions));
    foreach ($filtered_modified_files as $file) {
      $messages[] = $this->t('The hash for @file does not match its original. Updates that include that file will fail and require manual intervention.', ['@file' => $file]);
    }
    return $messages;
  }

}
