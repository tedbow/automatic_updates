<?php

namespace Drupal\package_manager\Validator;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\package_manager\ProjectInfo;
use Drupal\package_manager\LegacyVersionUtility;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\package_manager\Event\PreApplyEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates that updated projects are secure and supported.
 *
 * @internal
 *   This class is an internal part of the module's update handling and
 *   should not be used by external code.
 */
final class SupportedReleaseValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Checks if the given version of a project is supported.
   *
   * Checks if the given version of the given project is in the core update
   * system's list of known, secure, installable releases of that project.
   * considered a supported release by verifying if the project is found in the
   * core update system's list of known, secure, and installable releases.
   *
   * @param string $name
   *   The name of the project.
   * @param string $semantic_version
   *   A semantic version number for the project.
   *
   * @return bool
   *   TRUE if the given version of the project is supported, otherwise FALSE.
   *   given version is not supported will return FALSE.
   */
  protected function isSupportedRelease(string $name, string $semantic_version): bool {
    $supported_releases = (new ProjectInfo($name))->getInstallableReleases();
    if (!$supported_releases) {
      return FALSE;
    }

    // If this version is found in the list of installable releases, it is
    // secured and supported.
    if (array_key_exists($semantic_version, $supported_releases)) {
      return TRUE;
    }
    // If the semantic version number wasn't in the list of
    // installable releases, convert it to a legacy version number and see
    // if the version number is in the list.
    $legacy_version = LegacyVersionUtility::convertToLegacyVersion($semantic_version);
    if ($legacy_version && array_key_exists($legacy_version, $supported_releases)) {
      return TRUE;
    }
    // Neither the semantic version nor the legacy version are in the list
    // of installable releases, so the release isn't supported.
    return FALSE;
  }

  /**
   * Checks that the packages are secure and supported.
   *
   * @param \Drupal\package_manager\Event\PreApplyEvent $event
   *   The event object.
   */
  public function checkStagedReleases(PreApplyEvent $event): void {
    $active = $event->getStage()->getActiveComposer();
    $staged = $event->getStage()->getStageComposer();
    $updated_packages = array_merge(
      $staged->getPackagesNotIn($active),
      $staged->getPackagesWithDifferentVersionsIn($active)
    );
    $unknown_packages = [];
    $unsupported_packages = [];
    foreach ($updated_packages as $package_name => $staged_package) {
      // Only packages of the types 'drupal-module' or 'drupal-theme' that
      // start with 'drupal/' will have update XML from drupal.org.
      if (!in_array($staged_package->getType(), ['drupal-module', 'drupal-theme'], TRUE)
         || !str_starts_with($package_name, 'drupal/')) {
        continue;
      }
      $project_name = $staged->getProjectForPackage($package_name);
      if (empty($project_name)) {
        $unknown_packages[] = $package_name;
        continue;
      }
      $semantic_version = $staged_package->getPrettyVersion();
      if (!$this->isSupportedRelease($project_name, $semantic_version)) {
        $unsupported_packages[] = new FormattableMarkup('@project_name (@package_name) @version', [
          '@project_name' => $project_name,
          '@package_name' => $package_name,
          '@version' => $semantic_version,
        ]);
      }
    }
    if ($unsupported_packages) {
      $summary = $this->formatPlural(
        count($unsupported_packages),
        'Cannot update because the following project version is not in the list of installable releases.',
        'Cannot update because the following project versions are not in the list of installable releases.'
      );
      $event->addError($unsupported_packages, $summary);
    }
    if ($unknown_packages) {
      $summary = $this->formatPlural(
        count($unknown_packages),
        'Cannot update because the following new or updated Drupal package does not have project information.',
        'Cannot update because the following new or updated Drupal packages do not have project information.',
      );
      $event->addError($unknown_packages, $summary);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PreApplyEvent::class => 'checkStagedReleases',
    ];
  }

}
