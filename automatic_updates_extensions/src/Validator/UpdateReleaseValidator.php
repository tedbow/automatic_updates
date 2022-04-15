<?php

namespace Drupal\automatic_updates_extensions\Validator;

use Drupal\automatic_updates\ProjectInfo;
use Drupal\automatic_updates_extensions\ExtensionUpdater;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\package_manager\Event\PreCreateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates that updated projects are secure and supported.
 */
class UpdateReleaseValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Checks that the update projects are secure and supported.
   *
   * @param \Drupal\package_manager\Event\PreCreateEvent $event
   *   The event object.
   */
  public function checkRelease(PreCreateEvent $event): void {
    $stage = $event->getStage();
    // This check only works with Automatic Updates Extensions.
    if (!$stage instanceof ExtensionUpdater) {
      return;
    }

    $all_versions = $stage->getPackageVersions();
    $messages = [];
    foreach (['production', 'dev'] as $package_type) {
      foreach ($all_versions[$package_type] as $package_name => $version) {
        $package_parts = explode('/', $package_name);
        $project_name = $package_parts[1];
        // If the version isn't in the list of installable releases, then it
        // isn't secure and supported and the user should receive an error.
        $releases = (new ProjectInfo($project_name))->getInstallableReleases();
        if (empty($releases) || !array_key_exists($version, $releases)) {
          $messages[] = $this->t('Project @project_name to version @version', [
            '@project_name' => $project_name,
            '@version' => $version,
          ]);
        }
      }
    }
    if ($messages) {
      $summary = $this->formatPlural(
        count($messages),
        'Cannot update because the following project version is not in the list of installable releases.',
        'Cannot update because the following project versions are not in the list of installable releases.'
      );
      $event->addError($messages, $summary);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      PreCreateEvent::class => 'checkRelease',
    ];
  }

}
