<?php

namespace Drupal\automatic_updates\Validator\Version;

use Drupal\automatic_updates\Updater;
use Drupal\Core\Extension\ExtensionVersion;

class StableTargetVersionValidator extends PolicyRule {

  /**
   * {@inheritdoc}
   */
  protected function doValidation(Updater $updater, string $installed_version, ?string $target_version): array {
    $extra = ExtensionVersion::createFromVersionString($target_version)
      ->getVersionExtra();

    if ($extra) {
      return [
        $this->t('Drupal cannot be automatically updated during cron to the recommended version, @target_version, because Automatic Updates only supports updating to stable versions during cron.'),
      ];
    }
    return [];
  }

}
