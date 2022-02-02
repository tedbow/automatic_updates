<?php

namespace Drupal\automatic_updates;

use Drupal\automatic_updates\Exception\UpdateException;
use Drupal\package_manager\Event\StageEvent;
use Drupal\package_manager\Exception\StageValidationException;
use Drupal\package_manager\Stage;

/**
 * Defines a service to perform updates.
 *
 * Currently, only updates to Drupal core are supported. This is done by
 * changing the constraint for either 'drupal/core' or 'drupal/core-recommended'
 * in the project-level composer.json. If neither package is directly required
 * in the project-level composer.json, a requirement will be added.
 */
class Updater extends Stage {

  /**
   * Begins the update.
   *
   * @param string[] $project_versions
   *   The versions of the packages to update to, keyed by package name.
   *
   * @return string
   *   The unique ID of the stage.
   *
   * @throws \InvalidArgumentException
   *   Thrown if no project version for Drupal core is provided.
   */
  public function begin(array $project_versions): string {
    if (count($project_versions) !== 1 || !array_key_exists('drupal', $project_versions)) {
      throw new \InvalidArgumentException("Currently only updates to Drupal core are supported.");
    }

    $composer = $this->getActiveComposer();
    $package_versions = [
      'production' => [],
      'dev' => [],
    ];

    $require_dev = $composer->getComposer()
      ->getPackage()
      ->getDevRequires();
    foreach ($composer->getCorePackageNames() as $package) {
      $group = array_key_exists($package, $require_dev) ? 'dev' : 'production';
      $package_versions[$group][$package] = $project_versions['drupal'];
    }

    // Ensure that package versions are available to pre-create event
    // subscribers. We can't use ::setMetadata() here because it requires the
    // stage to be claimed, but that only happens during ::create().
    $this->tempStore->set(static::TEMPSTORE_METADATA_KEY, [
      'packages' => $package_versions,
    ]);
    return $this->create();
  }

  /**
   * Returns the package versions that will be required during the update.
   *
   * @return string[][]
   *   An array with two sub-arrays: 'production' and 'dev'. Each is a set of
   *   package versions, where the keys are package names and the values are
   *   version constraints understood by Composer.
   */
  public function getPackageVersions(): array {
    return $this->getMetadata('packages');
  }

  /**
   * Stages the update.
   */
  public function stage(): void {
    $this->checkOwnership();

    // Convert an associative array of package versions, keyed by name, to
    // command-line arguments in the form `vendor/name:version`.
    $map = function (array $versions): array {
      $requirements = [];
      foreach ($versions as $package => $version) {
        $requirements[] = "$package:$version";
      }
      return $requirements;
    };
    $versions = array_map($map, $this->getPackageVersions());
    $this->require($versions['production'], $versions['dev']);
  }

  /**
   * {@inheritdoc}
   */
  protected function dispatch(StageEvent $event): void {
    try {
      parent::dispatch($event);
    }
    catch (StageValidationException $e) {
      throw new UpdateException($e->getResults(), $e->getMessage() ?: "Unable to complete the update because of errors.", $e->getCode(), $e);
    }
  }

}
