<?php

namespace Drupal\package_manager\Validator;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;
use Drupal\package_manager\PathLocator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates that newly installed packages don't overwrite existing directories.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
final class OverwriteExistingPackagesValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The path locator service.
   *
   * @var \Drupal\package_manager\PathLocator
   */
  protected $pathLocator;

  /**
   * Constructs a OverwriteExistingPackagesValidator object.
   *
   * @param \Drupal\package_manager\PathLocator $path_locator
   *   The path locator service.
   */
  public function __construct(PathLocator $path_locator) {
    $this->pathLocator = $path_locator;
  }

  /**
   * Validates that new installed packages don't overwrite existing directories.
   */
  public function validateStagePreOperation(PreOperationStageEvent $event): void {
    $stage = $event->getStage();
    $active_composer = $stage->getActiveComposer();
    $stage_composer = $stage->getStageComposer();
    $active_dir = $this->pathLocator->getProjectRoot();
    $stage_dir = $stage->getStageDirectory();
    $new_packages = $stage_composer->getPackagesNotIn($active_composer);
    $installed_packages_data = $stage_composer->getInstalledPackagesData();

    // Although unlikely, it is possible that package data could be missing for
    // some new packages.
    $missing_new_packages = array_diff_key($new_packages, $installed_packages_data);
    if ($missing_new_packages) {
      $missing_new_packages = array_keys($missing_new_packages);
      $event->addError($missing_new_packages, $this->t('Package Manager could not get the data for the following packages:'));
      return;
    }

    $new_installed_data = array_intersect_key($installed_packages_data, $new_packages);
    foreach ($new_installed_data as $package_name => $data) {
      $relative_path = str_replace($stage_dir, '', $data['install_path']);
      if (is_dir($active_dir . DIRECTORY_SEPARATOR . $relative_path)) {
        $event->addError([
          $this->t('The new package @package will be installed in the directory @path, which already exists but is not managed by Composer.', [
            '@package' => $package_name,
            '@path' => $relative_path,
          ]),
        ]);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      PreApplyEvent::class => 'validateStagePreOperation',
    ];
  }

}
