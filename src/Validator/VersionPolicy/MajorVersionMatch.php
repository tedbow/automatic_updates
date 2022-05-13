<?php

namespace Drupal\automatic_updates\Validator\VersionPolicy;

use Drupal\automatic_updates\Updater;
use Drupal\Core\Extension\ExtensionVersion;

/**
 * A policy rule that requires updating within the same major version.
 *
 * @internal
 *   This is an internal part of Automatic Updates' version policy for
 *   Drupal core. It may be changed or removed at any time without warning.
 *   External code should not interact with this class.
 */
class MajorVersionMatch extends RuleBase {

  /**
   * {@inheritdoc}
   */
  protected function doValidation(Updater $updater, string $installed_version, ?string $target_version): array {
    $installed_major = ExtensionVersion::createFromVersionString($installed_version)
      ->getMajorVersion();
    $target_major = ExtensionVersion::createFromVersionString($target_version)
      ->getMajorVersion();

    if ($installed_major !== $target_major) {
      return [
        $this->t('Drupal cannot be automatically updated from its current version, @installed_version, to the recommended version, @target_version, because automatic updates from one major version to another are not supported.'),
      ];
    }
    return [];
  }

}
