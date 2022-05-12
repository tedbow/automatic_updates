<?php

namespace Drupal\automatic_updates\Validator\Version;

use Drupal\automatic_updates\CronUpdater;
use Drupal\automatic_updates\ProjectInfo;
use Drupal\automatic_updates\Updater;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;

abstract class VersionValidatorBase {

  use StringTranslationTrait;

  public function validate(Updater $updater, string $installed_version, ?string $target_version): array {
    $variables = [
      '@installed_version' => $installed_version,
      '@target_version' => $target_version,
    ];
    $map = function (TranslatableMarkup $message) use ($variables): TranslatableMarkup {
      // @codingStandardsIgnoreLine
      return new TranslatableMarkup($message->getUntranslatedString(), $message->getArguments() + $variables, $message->getOptions(), $this->getStringTranslation());
    };
    return array_map($map, $this->doValidation($updater, $installed_version, $target_version));
  }

  abstract protected function doValidation(Updater $updater, string $installed_version, ?string $target_version): array;

  protected function getAvailableReleases(Updater $updater): array {
    $project_info = new ProjectInfo('drupal');
    $available_releases = $project_info->getInstallableReleases() ?? [];

    if ($updater instanceof CronUpdater) {
      $available_releases = array_reverse($available_releases);
    }
    return $available_releases;
  }

}
