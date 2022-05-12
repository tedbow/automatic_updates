<?php

namespace Drupal\automatic_updates\Validator;

use Composer\Semver\Semver;
use Drupal\automatic_updates\CronUpdater;
use Drupal\automatic_updates\ProjectInfo;
use Drupal\automatic_updates\VersionParsingTrait;
use Drupal\Core\Extension\ExtensionVersion;
use Drupal\package_manager\Stage;
use Drupal\package_manager\ValidationResult;

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

  /**
   * {@inheritdoc}
   */
  public function getValidationResult(string $to_version_string): ?ValidationResult {
    $from_version_string = $this->getCoreVersion();
    $to_version = ExtensionVersion::createFromVersionString($to_version_string);
    $from_version = ExtensionVersion::createFromVersionString($from_version_string);
    $variables = [
      '@to_version' => $to_version_string,
      '@from_version' => $from_version_string,
    ];
    // @todo Return multiple validation messages and summary in
    //   https://www.drupal.org/project/automatic_updates/issues/3272068.
    // Validate that both the from and to versions are stable releases.
    if ($to_version->getVersionExtra()) {
      // Because we do not support updating to a new minor version during
      // cron it is probably impossible to update from a stable version to
      // a unstable/pre-release version, but we should check this condition
      // just in case.
      return ValidationResult::createError([
        $this->t('Drupal cannot be automatically updated during cron to the recommended version, @to_version, because Automatic Updates only supports updating to stable versions during cron.', $variables),
      ]);
    }

    if ($from_version->getMinorVersion() !== $to_version->getMinorVersion()) {
      return ValidationResult::createError([
        $this->t('Drupal cannot be automatically updated from its current version, @from_version, to the recommended version, @to_version, because automatic updates from one minor version to another are not supported during cron.', $variables),
      ]);
    }

    // Only updating to the next patch release is supported during cron.
    $supported_patch_version = $from_version->getMajorVersion() . '.' . $from_version->getMinorVersion() . '.' . (((int) static::getPatchVersion($from_version_string)) + 1);
    if ($to_version_string !== $supported_patch_version) {
      return ValidationResult::createError([
        $this->t('Drupal cannot be automatically updated during cron from its current version, @from_version, to the recommended version, @to_version, because Automatic Updates only supports 1 patch version update during cron.', $variables),
      ]);
    }

    // We cannot use dependency injection to get the cron updater because that
    // would create a circular service dependency.
    $level = \Drupal::service('automatic_updates.cron_updater')
      ->getMode();

    // If both the from and to version numbers are valid check if the current
    // settings only allow security updates during cron and if so ensure the
    // update release is a security release.
    if ($level === CronUpdater::SECURITY) {
      $releases = (new ProjectInfo('drupal'))->getInstallableReleases();
      // @todo Remove this check and add validation to
      //   \Drupal\automatic_updates\Validator\UpdateVersionValidator::getValidationResult()
      //   to ensure the update release is always secure and supported in
      //   https://www.drupal.org/i/3271468.
      if (!isset($releases[$to_version_string])) {
        return ValidationResult::createError([
          $this->t('Drupal cannot be automatically updated during cron from its current version, @from_version, to the recommended version, @to_version, because @to_version is not a valid release.', $variables),
        ]);
      }
      if (!$releases[$to_version_string]->isSecurityRelease()) {
        return ValidationResult::createError([
          $this->t('Drupal cannot be automatically updated during cron from its current version, @from_version, to the recommended version, @to_version, because @to_version is not a security release.', $variables),
        ]);
      }
    }
    return NULL;
  }

}
