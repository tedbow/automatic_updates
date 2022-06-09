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
    if ($stage instanceof ExtensionUpdater) {
      $active_composer = $stage->getActiveComposer();
      $installed_packages = $active_composer->getInstalledPackages();
      $missing_packages = [];
      if ($event instanceof PreCreateEvent) {
        $package_versions = $stage->getPackageVersions();
        foreach (['production', 'dev'] as $package_type) {
          $missing_packages = array_merge($missing_packages, array_diff_key($package_versions[$package_type], $installed_packages));
        }
      }
      else {
        $missing_packages = $stage->getStageComposer()
          ->getPackagesNotIn($active_composer);
        // For new dependency added in the stage will are only concerned with
        // ones that are Drupal projects that have Update XML from Drupal.org
        // Since the Update module does allow use to check any of these projects
        // if they don't exist in the active code base. Other types of projects
        // even if they are in the 'drupal/' namespace they would not have
        // Update XML on Drupal.org so it doesn't matter if they are in the
        // active codebase or not.
        $types = [
          'drupal-module',
          'drupal-theme',
          'drupal-profile',
        ];
        $filter = function (PackageInterface $package) use ($types): bool {
          return in_array($package->getType(), $types);
        };
        $missing_packages = array_filter($missing_packages, $filter);
        // Saving only the packages whose name starts with drupal/.
        $missing_packages = array_filter($missing_packages, function (string $key) {
          return strpos($key, 'drupal/') === 0;
        }, ARRAY_FILTER_USE_KEY);
      }
      if ($missing_packages) {
        $missing_projects = [];
        foreach ($missing_packages as $package => $version) {
          // Removing drupal/ from package name for better user presentation.
          $project = str_replace('drupal/', '', $package);
          $missing_projects[] = $project;
        }
        if ($missing_projects) {
          $event->addError($missing_projects, $this->t('Automatic Updates can only update projects that were installed via Composer. The following packages are not installed through composer:'));
        }
      }
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

}
