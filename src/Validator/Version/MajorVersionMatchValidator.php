<?php

namespace Drupal\automatic_updates\Validator\Version;

use Drupal\automatic_updates\Updater;
use Drupal\Core\Extension\ExtensionVersion;

class MajorVersionMatchValidator extends PolicyRule {

  /**
   * {@inheritdoc}
   */
  protected function doValidation(Updater $updater, string $installed_version, ?string $target_version): array {
    $installed_major = ExtensionVersion::createFromVersionString($installed_version)
      ->getMajorVersion();
    $target_major = ExtensionVersion::createFromVersionString($target_version)
      ->getMajorVersion();

    if ($installed_major !== $target_major) {
      return [
        $this->t('Drupal cannot be automatically updated from its current version, @installed_version, to the recommended version, @target_version, because automatic updates from one major version to another are not supported.'),
      ];
    }
    return [];
  }

}
