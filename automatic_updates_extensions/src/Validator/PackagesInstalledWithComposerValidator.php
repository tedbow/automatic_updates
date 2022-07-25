<?php

namespace Drupal\automatic_updates_extensions\Validator;

use Composer\Package\PackageInterface;
use Drupal\automatic_updates_extensions\ExtensionUpdater;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates packages are installed via Composer.
 */
class PackagesInstalledWithComposerValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Constructs a InstalledPackagesValidator object.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The translation service.
   */
  public function __construct(TranslationInterface $translation) {
    $this->setStringTranslation($translation);
  }

  /**
   * Validates that packages are installed with composer or not.
   *
   * @param \Drupal\package_manager\Event\PreOperationStageEvent $event
   *   The event object.
   */
  public function checkPackagesInstalledWithComposer(PreOperationStageEvent $event): void {
    $stage = $event->getStage();

    if (!$stage instanceof ExtensionUpdater) {
      return;
    }

    $missing_packages = $this->getPackagesNotInstalledWithComposer($event);
    if ($missing_packages) {
      // Removing drupal/ from package names for better user presentation.
      $missing_projects = str_replace('drupal/', '', array_keys($missing_packages));
      $event->addError($missing_projects, $this->t('Automatic Updates can only update projects that were installed via Composer. The following packages are not installed through composer:'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      PreCreateEvent::class => 'checkPackagesInstalledWithComposer',
      PreApplyEvent::class => 'checkPackagesInstalledWithComposer',
    ];
  }

  /**
   * Gets the packages which aren't installed via composer.
   *
   * @param \Drupal\package_manager\Event\PreOperationStageEvent $event
   *   The event object.
   *
   * @return \Composer\Package\PackageInterface[]
   *   Packages not installed via composer.
   */
  protected function getPackagesNotInstalledWithComposer(PreOperationStageEvent $event): array {
    $stage = $event->getStage();
    $active_composer = $stage->getActiveComposer();
    $installed_packages = $active_composer->getInstalledPackages();

    $missing_packages = [];
    // During pre-create there is no stage directory, so missing packages can be
    // found using package versions that will be required during the update,
    // while during pre-apply there is stage directory which will be used to
    // find missing packages.
    if ($event instanceof PreCreateEvent) {
      $package_versions = $stage->getPackageVersions();
      foreach (['production', 'dev'] as $package_group) {
        $missing_packages = array_merge($missing_packages, array_diff_key($package_versions[$package_group], $installed_packages));
      }
    }
    else {
      $missing_packages = $stage->getStageComposer()->getPackagesNotIn($active_composer);

      // The core update system can only fetch release information for modules,
      // themes, or profiles that are in the active code base (whether they're
      // installed or not). If a package is not one of those types, ignore it
      // even if its vendor namespace is `drupal`.
      $types = [
        'drupal-module',
        'drupal-theme',
        'drupal-profile',
      ];
      $filter = function (PackageInterface $package) use ($types): bool {
        return in_array($package->getType(), $types, TRUE);
      };
      $missing_packages = array_filter($missing_packages, $filter);

      // The core update system can only fetch release information for drupal
      // projects, so saving only the packages whose name starts with drupal/.
      $missing_packages = array_filter($missing_packages, function (string $key) {
        return str_starts_with($key, 'drupal/');
      }, ARRAY_FILTER_USE_KEY);
    }
    return $missing_packages;
  }

}
