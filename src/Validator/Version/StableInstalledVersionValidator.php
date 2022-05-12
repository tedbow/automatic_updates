<?php

namespace Drupal\automatic_updates\Validator\Version;

use Drupal\automatic_updates\Updater;
use Drupal\Core\Extension\ExtensionVersion;

class StableInstalledVersionValidator extends VersionValidatorBase {

  /**
   * {@inheritdoc}
   */
  protected function doValidation(Updater $updater, string $installed_version, ?string $target_version): array {
    $extra = ExtensionVersion::createFromVersionString($installed_version)
      ->getVersionExtra();

    if ($extra) {
      return [
        $this->t('Drupal cannot be automatically updated during cron from its current version, @installed_version, because Automatic Updates only supports updating from stable versions during cron.'),
      ];
    }
    return [];
  }

}
