<?php

namespace Drupal\automatic_updates\Validator;

use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates the staged Drupal projects.
 */
final class StagedProjectsValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Constructs a StagedProjectsValidation object.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The translation service.
   */
  public function __construct(TranslationInterface $translation) {
    $this->setStringTranslation($translation);
  }

  /**
   * Validates the staged packages.
   *
   * @param \Drupal\package_manager\Event\PreApplyEvent $event
   *   The event object.
   */
  public function validateStagedProjects(PreApplyEvent $event): void {
    $stage = $event->getStage();
    try {
      $active_packages = $stage->getActiveComposer()->getDrupalExtensionPackages();
      $staged_packages = $stage->getStageComposer()->getDrupalExtensionPackages();
    }
    catch (\Throwable $e) {
      $event->addError([
        $e->getMessage(),
      ]);
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
            '@type' => $type_map[$new_package->getType()],
            '@name' => $new_package->getName(),
          ]
        );
      }
      $new_packages_summary = $this->formatPlural(
        count($new_packages_messages),
        'The update cannot proceed because the following Drupal project was installed during the update.',
        'The update cannot proceed because the following Drupal projects were installed during the update.'
      );
      $event->addError($new_packages_messages, $new_packages_summary);
    }

    // Check if any Drupal projects were removed.
    if ($removed_packages = array_diff_key($active_packages, $staged_packages)) {
      $removed_packages_messages = [];
      foreach ($removed_packages as $removed_package) {
        $removed_packages_messages[] = $this->t(
          "@type '@name' removed.",
          [
            '@type' => $type_map[$removed_package->getType()],
            '@name' => $removed_package->getName(),
          ]
        );
      }
      $removed_packages_summary = $this->formatPlural(
        count($removed_packages_messages),
        'The update cannot proceed because the following Drupal project was removed during the update.',
        'The update cannot proceed because the following Drupal projects were removed during the update.'
      );
      $event->addError($removed_packages_messages, $removed_packages_summary);
    }

    // Get all the packages that are neither newly installed or removed to
    // check if their version numbers changed.
    if ($pre_existing_packages = array_diff_key($staged_packages, $removed_packages, $new_packages)) {
      foreach ($pre_existing_packages as $package_name => $staged_existing_package) {
        $active_package = $active_packages[$package_name];
        if ($staged_existing_package->getVersion() !== $active_package->getVersion()) {
          $version_change_messages[] = $this->t(
            "@type '@name' from @active_version to  @staged_version.",
            [
              '@type' => $type_map[$active_package->getType()],
              '@name' => $active_package->getName(),
              '@staged_version' => $staged_existing_package->getPrettyVersion(),
              '@active_version' => $active_package->getPrettyVersion(),
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
        $event->addError($version_change_messages, $version_change_summary);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[PreApplyEvent::class][] = ['validateStagedProjects'];
    return $events;
  }

}
