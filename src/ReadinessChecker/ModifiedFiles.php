<?php

namespace Drupal\automatic_updates\ReadinessChecker;

use Drupal\automatic_updates\Services\ModifiedFilesInterface;
use Drupal\Core\Extension\ExtensionList;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Modified code checker.
 */
class ModifiedFiles implements ReadinessCheckerInterface {
  use StringTranslationTrait;

  /**
   * The modified files service.
   *
   * @var \Drupal\automatic_updates\Services\ModifiedFilesInterface
   */
  protected $modifiedFiles;

  /**
   * The module extension list.
   *
   * @var \Drupal\Core\Extension\ExtensionList
   */
  protected $modules;

  /**
   * The profile extension list.
   *
   * @var \Drupal\Core\Extension\ExtensionList
   */
  protected $profiles;

  /**
   * The theme extension list.
   *
   * @var \Drupal\Core\Extension\ExtensionList
   */
  protected $themes;

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
    $this->modules = $modules;
    $this->profiles = $profiles;
    $this->themes = $themes;
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
    $extensions['drupal'] = $this->modules->get('system')->info;
    foreach ($this->getExtensionsTypes() as $extension_type) {
      foreach ($this->getInfos($extension_type) as $extension_name => $info) {
        if (substr($this->getPath($extension_type, $extension_name), 0, 4) !== 'core') {
          $extensions[$extension_name] = $info;
        }
      }
    }
    foreach ($this->modifiedFiles->getModifiedFiles($extensions) as $file) {
      $messages[] = $this->t('The hash for @file does not match its original. Updates that include that file will fail and require manual intervention.', ['@file' => $file]);
    }
    return $messages;
  }

  /**
   * Get the extension types.
   *
   * @return array
   *   The extension types.
   */
  protected function getExtensionsTypes() {
    return ['modules', 'profiles', 'themes'];
  }

  /**
   * Returns an array of info files information of available extensions.
   *
   * @param string $extension_type
   *   The extension type.
   *
   * @return array
   *   An associative array of extension information arrays, keyed by extension
   *   name.
   */
  protected function getInfos($extension_type) {
    return $this->{$extension_type}->getAllAvailableInfo();
  }

  /**
   * Returns an extension file path.
   *
   * @param string $extension_type
   *   The extension type.
   * @param string $extension_name
   *   The extension name.
   *
   * @return string
   *   An extension file path or NULL if it does not exist.
   */
  protected function getPath($extension_type, $extension_name) {
    if (!isset($this->paths[$extension_type])) {
      $this->paths[$extension_type] = $this->{$extension_type}->getPathnames();
    }
    return isset($this->paths[$extension_type][$extension_name]) ? $this->paths[$extension_type][$extension_name] : NULL;
  }

}
