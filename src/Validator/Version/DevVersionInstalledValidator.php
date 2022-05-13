<?php

namespace Drupal\automatic_updates\Validator\Version;

use Drupal\automatic_updates\Updater;
use Drupal\Core\Extension\ExtensionVersion;

class DevVersionInstalledValidator extends PolicyRule {

  /**
   * {@inheritdoc}
   */
  protected function doValidation(Updater $updater, string $installed_version, ?string $target_version): array {
    $extra = ExtensionVersion::createFromVersionString($installed_version)
      ->getVersionExtra();

    if ($extra === 'dev') {
      return [
        $this->t('Drupal cannot be automatically updated from the installed version, @installed_version, because automatic updates from a dev version to any other version are not supported.'),
      ];
    }
    return [];
  }

}
