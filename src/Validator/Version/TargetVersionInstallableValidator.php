<?php

namespace Drupal\automatic_updates\Validator\Version;

use Drupal\automatic_updates\Updater;

class TargetVersionInstallableValidator extends PolicyRule {

  /**
   * {@inheritdoc}
   */
  protected function doValidation(Updater $updater, string $installed_version, ?string $target_version): array {
    // If the target version isn't in the list of installable releases, then it
    // isn't secure and supported and we should flag an error.
    $releases = $this->getAvailableReleases($updater);
    if (empty($releases) || !array_key_exists($target_version, $releases)) {
      return [
        $this->t('Cannot update Drupal core to @target_version because it is not in the list of installable releases.'),
      ];
    }
    return [];
  }

}
