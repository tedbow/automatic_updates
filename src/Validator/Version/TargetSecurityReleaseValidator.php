<?php

namespace Drupal\automatic_updates\Validator\Version;

use Drupal\automatic_updates\Updater;

class TargetSecurityReleaseValidator extends VersionValidatorBase {

  /**
   * {@inheritdoc}
   */
  protected function doValidation(Updater $updater, string $installed_version, ?string $target_version): array {
    $releases = $this->getAvailableReleases($updater);

    if (!$releases[$target_version]->isSecurityRelease()) {
      return [
        $this->t('Drupal cannot be automatically updated during cron from its current version, @installed_version, to the recommended version, @target_version, because @target_version is not a security release.'),
      ];
    }
    return [];
  }

}
