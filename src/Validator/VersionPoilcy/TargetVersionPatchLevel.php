<?php

namespace Drupal\automatic_updates\Validator\VersionPolicy;

use Drupal\automatic_updates\Updater;
use Drupal\automatic_updates\VersionParsingTrait;
use Drupal\Core\Extension\ExtensionVersion;

/**
 * A policy rule requiring the target version to be one patch release ahead.
 */
class TargetVersionPatchLevel extends RuleBase {

  use VersionParsingTrait;

  /**
   * {@inheritdoc}
   */
  protected function doValidation(Updater $updater, string $installed_version, ?string $target_version): array {
    $from_version = ExtensionVersion::createFromVersionString($installed_version);

    $supported_patch_version = $from_version->getMajorVersion() . '.' . $from_version->getMinorVersion() . '.' . (((int) static::getPatchVersion($installed_version)) + 1);
    if ($target_version !== $supported_patch_version) {
      return [
        $this->t('Drupal cannot be automatically updated during cron from its current version, @installed_version, to the recommended version, @target_version, because Automatic Updates only supports 1 patch version update during cron.'),
      ];
    }
    return [];
  }

}
