<?php

namespace Drupal\automatic_updates\Validator\Version;

use Drupal\automatic_updates\Updater;
use Drupal\Core\Extension\ExtensionVersion;

class MinorUpdateValidator extends VersionValidatorBase {

  /**
   * {@inheritdoc}
   */
  protected function doValidation(Updater $updater, string $installed_version, ?string $target_version): array {
    $installed_minor = ExtensionVersion::createFromVersionString($installed_version)
      ->getMinorVersion();
    $target_minor = ExtensionVersion::createFromVersionString($target_version)
      ->getMinorVersion();

    if ($installed_minor !== $target_minor) {
      return [
        $this->t('Drupal cannot be automatically updated from its current version, @installed_version, to the recommended version, @target_version, because automatic updates from one minor version to another are not supported during cron.'),
      ];
    }
    return [];
  }

}
