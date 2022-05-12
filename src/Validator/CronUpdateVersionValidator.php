<?php

namespace Drupal\automatic_updates\Validator;

use Composer\Semver\Semver;
use Drupal\automatic_updates\CronUpdater;
use Drupal\automatic_updates\ProjectInfo;
use Drupal\automatic_updates\VersionParsingTrait;
use Drupal\package_manager\Stage;

/**
 * Validates the target version of Drupal core before a cron update.
 *
 * @internal
 *   This class is an internal part of the module's cron update handling and
 *   should not be used by external code.
 */
final class CronUpdateVersionValidator extends UpdateVersionValidator {

  use VersionParsingTrait;

  /**
   * {@inheritdoc}
   */
  protected static function isStageSupported(Stage $stage): bool {
    // @todo Add test coverage for the call to getMode() in
    //   https://www.drupal.org/i/3276662.
    return $stage instanceof CronUpdater && $stage->getMode() !== CronUpdater::DISABLED;
  }

  /**
   * {@inheritdoc}
   */
  protected function getNextPossibleUpdateVersion(): ?string {
    $project_info = new ProjectInfo('drupal');
    $installed_version = $project_info->getInstalledVersion();
    if ($possible_releases = $project_info->getInstallableReleases()) {
      // The next possible update version for cron should be the lowest possible
      // release.
      $possible_release = array_pop($possible_releases);
      if (Semver::satisfies($possible_release->getVersion(), "~$installed_version")) {
        return $possible_release->getVersion();
      }
    }
    return NULL;
  }

}
