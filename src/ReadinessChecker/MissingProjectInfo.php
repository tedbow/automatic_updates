<?php

namespace Drupal\automatic_updates\ReadinessChecker;

use Drupal\Core\Extension\ExtensionList;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use DrupalFinder\DrupalFinder;

/**
 * Missing project info checker.
 */
class MissingProjectInfo extends Filesystem {
  use StringTranslationTrait;

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
   * MissingProjectInfo constructor.
   *
   * @param \DrupalFinder\DrupalFinder $drupal_finder
   *   The Drupal finder.
   * @param \Drupal\Core\Extension\ExtensionList $modules
   *   The module extension list.
   * @param \Drupal\Core\Extension\ExtensionList $profiles
   *   The profile extension list.
   * @param \Drupal\Core\Extension\ExtensionList $themes
   *   The theme extension list.
   */
  public function __construct(DrupalFinder $drupal_finder, ExtensionList $modules, ExtensionList $profiles, ExtensionList $themes) {
    $this->drupalFinder = $drupal_finder;
    $this->modules = $modules;
    $this->profiles = $profiles;
    $this->themes = $themes;
  }

  /**
   * {@inheritdoc}
   */
  protected function doCheck() {
    return $this->missingProjectInfoCheck();
  }

  /**
   * Check for projects missing project info.
   *
   * @return array
   *   An array of translatable strings if any checks fail.
   */
  protected function missingProjectInfoCheck() {
    $messages = [];
    foreach ($this->getExtensionsTypes() as $extension_type) {
      foreach ($this->getInfos($extension_type) as $extension_name => $info) {
        if (empty($info['version'])) {
          $messages[] = $this->t('The project "@extension" will not be updated because it is missing the "version" key in the @extension.info.yml file.', ['@extension' => $extension_name]);
        }
        if (empty($info['project'])) {
          $messages[] = $this->t('The project "@extension" will not be updated because it is missing the "project" key in the @extension.info.yml file.', ['@extension' => $extension_name]);
        }
      }
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

}
