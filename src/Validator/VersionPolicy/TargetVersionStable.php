<?php

namespace Drupal\automatic_updates\Validator\VersionPolicy;

use Drupal\Core\Extension\ExtensionVersion;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * A policy rule requiring the target version to be a stable release.
 *
 * @internal
 *   This is an internal part of Automatic Updates' version policy for
 *   Drupal core. It may be changed or removed at any time without warning.
 *   External code should not interact with this class.
 */
class TargetVersionStable {

  use StringTranslationTrait;

  /**
   * Checks that the target version of Drupal is a stable release.
   *
   * @param string $installed_version
   *   The installed version of Drupal.
   * @param string|null $target_version
   *   The target version of Drupal, or NULL if not known.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   The error messages, if any.
   */
  public function validate(string $installed_version, ?string $target_version): array {
    $extra = ExtensionVersion::createFromVersionString($target_version)
      ->getVersionExtra();

    if ($extra) {
      return [
        $this->t('Drupal cannot be automatically updated during cron to @target_version, because Automatic Updates only supports updating to stable versions during cron.', [
          '@target_version' => $target_version,
        ]),
      ];
    }
    return [];
  }

}
