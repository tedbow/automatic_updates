<?php

namespace Drupal\automatic_updates\Validator\VersionPolicy;

use Drupal\automatic_updates\Updater;
use Drupal\automatic_updates\Validator\VersionPolicyValidator;

/**
 * A policy rule requiring the target version to be an installable release.
 *
 * @internal
 *   This is an internal part of Automatic Updates' version policy for
 *   Drupal core. It may be changed or removed at any time without warning.
 *   External code should not interact with this class.
 */
class TargetVersionInstallable extends RuleBase {

  /**
   * {@inheritdoc}
   */
  protected function doValidation(Updater $updater, string $installed_version, ?string $target_version): array {
    // If the target version isn't in the list of installable releases, then it
    // isn't secure and supported and we should flag an error.
    $releases = VersionPolicyValidator::getAvailableReleases($updater);

    if (empty($releases) || !array_key_exists($target_version, $releases)) {
      return [
        $this->t('Cannot update Drupal core to @target_version because it is not in the list of installable releases.'),
      ];
    }
    return [];
  }

}
