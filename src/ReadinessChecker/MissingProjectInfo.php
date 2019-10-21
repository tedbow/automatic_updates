<?php

namespace Drupal\automatic_updates\ReadinessChecker;

use Drupal\automatic_updates\IgnoredPathsTrait;
use Drupal\automatic_updates\ProjectInfoTrait;
use Drupal\Core\Extension\ExtensionList;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Missing project info checker.
 */
class MissingProjectInfo implements ReadinessCheckerInterface {
  use IgnoredPathsTrait;
  use ProjectInfoTrait;
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
   * @param \Drupal\Core\Extension\ExtensionList $modules
   *   The module extension list.
   * @param \Drupal\Core\Extension\ExtensionList $profiles
   *   The profile extension list.
   * @param \Drupal\Core\Extension\ExtensionList $themes
   *   The theme extension list.
   */
  public function __construct(ExtensionList $modules, ExtensionList $profiles, ExtensionList $themes) {
    $this->modules = $modules;
    $this->profiles = $profiles;
    $this->themes = $themes;
  }

  /**
   * {@inheritdoc}
   */
  public function run() {
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
      foreach ($this->getInfos($extension_type) as $info) {
        if ($this->isIgnoredPath($info['install path'])) {
          continue;
        }
        if (!$info['version']) {
          $messages[] = $this->t('The project "@extension" can not be updated because its version is either undefined or a dev release.', ['@extension' => $info['name']]);
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

}
