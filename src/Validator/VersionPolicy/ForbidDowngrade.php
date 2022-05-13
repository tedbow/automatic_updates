<?php

namespace Drupal\automatic_updates\Validator\VersionPolicy;

use Composer\Semver\Comparator;
use Drupal\automatic_updates\Updater;

/**
 * A policy rule that forbids downgrading.
 *
 * @internal
 *   This is an internal part of Automatic Updates' version policy for
 *   Drupal core. It may be changed or removed at any time without warning.
 *   External code should not interact with this class.
 */
class ForbidDowngrade extends RuleBase {

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
