<?php

namespace Drupal\automatic_updates\Validator\Version;

use Composer\Semver\Comparator;
use Drupal\automatic_updates\Updater;

class DowngradeValidator extends PolicyRule {

  /**
   * {@inheritdoc}
   */
  protected function doValidation(Updater $updater, string $installed_version, ?string $target_version): array {
    if (Comparator::lessThan($target_version, $installed_version)) {
      return [
        $this->t('Update version @target_version is lower than @installed_version, downgrading is not supported.'),
      ];
    }
    return [];
  }

}
