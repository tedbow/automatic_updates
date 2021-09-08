<?php

namespace Drupal\automatic_updates\Validator;

use Drupal\automatic_updates\AutomaticUpdatesEvents;
use Drupal\automatic_updates\Event\UpdateEvent;
use Drupal\automatic_updates\Exception\UpdateException;
use Drupal\automatic_updates\Updater;
use Drupal\automatic_updates\Validation\ValidationResult;
use Drupal\Component\Serialization\Json;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates the staged Drupal projects.
 */
final class StagedProjectsValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The updater service.
   *
   * @var \Drupal\automatic_updates\Updater
   */
  protected $updater;

  /**
   * Constructs a StagedProjectsValidation object.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The translation service.
   * @param \Drupal\automatic_updates\Updater $updater
   *   The updater service.
   */
  public function __construct(TranslationInterface $translation, Updater $updater) {
    $this->setStringTranslation($translation);
    $this->updater = $updater;
  }

  /**
   * Gets the Drupal packages in a composer.lock file.
   *
   * @param string $composer_lock_file
   *   The composer.lock file location.
   *
   * @return array[]
   *   The Drupal packages' information, as stored in the lock file, keyed by
   *   package name.
   */
  private function getDrupalPackagesFromLockFile(string $composer_lock_file): array {
    if (!file_exists($composer_lock_file)) {
      $result = ValidationResult::createError([
        $this->t("composer.lock file '@lock_file' not found.", ['@lock_file' => $composer_lock_file]),
      ]);
      throw new UpdateException(
        [$result],
        'The staged packages could not be evaluated because composer.lock file not found.'
      );
    }
    $composer_lock = file_get_contents($composer_lock_file);
    $drupal_packages = [];
    $data = Json::decode($composer_lock);
    $drupal_package_types = [
      'drupal-module',
      'drupal-theme',
      'drupal-custom-module',
      'drupal-custom-theme',
    ];
    $packages = $data['packages'] ?? [];
    $packages = array_merge($packages, $data['packages-dev'] ?? []);
    foreach ($packages as $package) {
      if (in_array($package['type'], $drupal_package_types, TRUE)) {
        $drupal_packages[$package['name']] = $package;
      }
    }

    return $drupal_packages;
  }

  /**
   * Validates the staged packages.
   *
   * @param \Drupal\automatic_updates\Event\UpdateEvent $event
   *   The update event.
   */
  public function validateStagedProjects(UpdateEvent $event): void {
    try {
      $active_packages = $this->getDrupalPackagesFromLockFile($this->updater->getActiveDirectory() . "/composer.lock");
      $staged_packages = $this->getDrupalPackagesFromLockFile($this->updater->getStageDirectory() . "/composer.lock");
    }
    catch (UpdateException $e) {
      foreach ($e->getValidationResults() as $result) {
        $event->addValidationResult($result);
      }
      return;
    }

    $type_map = [
      'drupal-module' => $this->t('module'),
      'drupal-custom-module' => $this->t('custom module'),
      'drupal-theme' => $this->t('theme'),
      'drupal-custom-theme' => $this->t('custom theme'),
    ];
    // Check if any new Drupal projects were installed.
    if ($new_packages = array_diff_key($staged_packages, $active_packages)) {
      $new_packages_messages = [];

      foreach ($new_packages as $new_package) {
        $new_packages_messages[] = $this->t(
          "@type '@name' installed.",
          [
            '@type' => $type_map[$new_package['type']],
            '@name' => $new_package['name'],
          ]
        );
      }
      $new_packages_summary = $this->formatPlural(
        count($new_packages_messages),
        'The update cannot proceed because the following Drupal project was installed during the update.',
        'The update cannot proceed because the following Drupal projects were installed during the update.'
      );
      $event->addValidationResult(ValidationResult::createError($new_packages_messages, $new_packages_summary));
    }

    // Check if any Drupal projects were removed.
    if ($removed_packages = array_diff_key($active_packages, $staged_packages)) {
      $removed_packages_messages = [];
      foreach ($removed_packages as $removed_package) {
        $removed_packages_messages[] = $this->t(
          "@type '@name' removed.",
          [
            '@type' => $type_map[$removed_package['type']],
            '@name' => $removed_package['name'],
          ]
        );
      }
      $removed_packages_summary = $this->formatPlural(
        count($removed_packages_messages),
        'The update cannot proceed because the following Drupal project was removed during the update.',
        'The update cannot proceed because the following Drupal projects were removed during the update.'
      );
      $event->addValidationResult(ValidationResult::createError($removed_packages_messages, $removed_packages_summary));
    }

    // Get all the packages that are neither newly installed or removed to
    // check if their version numbers changed.
    if ($pre_existing_packages = array_diff_key($staged_packages, $removed_packages, $new_packages)) {
      foreach ($pre_existing_packages as $package_name => $staged_existing_package) {
        $active_package = $active_packages[$package_name];
        if ($staged_existing_package['version'] !== $active_package['version']) {
          $version_change_messages[] = $this->t(
            "@type '@name' from @active_version to  @staged_version.",
            [
              '@type' => $type_map[$active_package['type']],
              '@name' => $active_package['name'],
              '@staged_version' => $staged_existing_package['version'],
              '@active_version' => $active_package['version'],
            ]
          );
        }
      }
      if (!empty($version_change_messages)) {
        $version_change_summary = $this->formatPlural(
          count($version_change_messages),
          'The update cannot proceed because the following Drupal project was unexpectedly updated. Only Drupal Core updates are currently supported.',
          'The update cannot proceed because the following Drupal projects were unexpectedly updated. Only Drupal Core updates are currently supported.'
        );
        $event->addValidationResult(ValidationResult::createError($version_change_messages, $version_change_summary));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[AutomaticUpdatesEvents::PRE_COMMIT][] = ['validateStagedProjects'];
    return $events;
  }

}
