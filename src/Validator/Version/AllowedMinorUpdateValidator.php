<?php

namespace Drupal\automatic_updates\Validator\Version;

use Drupal\automatic_updates\Updater;
use Drupal\Core\Extension\ExtensionVersion;

class AllowedMinorUpdateValidator extends VersionValidatorBase {

  /**
   * {@inheritdoc}
   */
  protected function doValidation(Updater $updater, string $installed_version, ?string $target_version): array {
    $installed_minor = ExtensionVersion::createFromVersionString($installed_version)
      ->getMinorVersion();
    $target_minor = ExtensionVersion::createFromVersionString($target_version)
      ->getMinorVersion();

    $minor_updates_allowed = \Drupal::config('automatic_updates.settings')
      ->get('allow_core_minor_updates');

    if ($installed_minor === $target_minor || $minor_updates_allowed) {
      return [];
    }

    return [
      $this->t('Drupal cannot be automatically updated from its current version, @installed_version, to the recommended version, @target_version, because automatic updates from one minor version to another are not supported.'),
    ];
  }

}
