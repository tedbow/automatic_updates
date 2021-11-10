<?php

namespace Drupal\automatic_updates;

use Drupal\automatic_updates\Exception\UpdateException;
use Drupal\Core\State\StateInterface;
use Drupal\package_manager\ComposerUtility;
use Drupal\package_manager\Event\StageEvent;
use Drupal\package_manager\Stage;
use Drupal\package_manager\StageException;

/**
 * Defines a service to perform updates.
 */
class Updater extends Stage {

  /**
   * State key under which to store the package versions targeted by the update.
   *
   * @var string
   */
  protected const PACKAGES_KEY = 'automatic_updates.packages';

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Constructs an Updater object.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param mixed ...$arguments
   *   Additional arguments to pass to the parent constructor.
   */
  public function __construct(StateInterface $state, ...$arguments) {
    $this->state = $state;
    parent::__construct(...$arguments);
  }

  /**
   * Determines if there is an active update in progress.
   *
   * @return bool
   *   TRUE if there is active update, otherwise FALSE.
   */
  public function hasActiveUpdate(): bool {
    $staged_dir = $this->pathLocator->getStageDirectory();
    if (is_dir($staged_dir) || $this->state->get(static::PACKAGES_KEY)) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Begins the update.
   *
   * @param string[] $project_versions
   *   The versions of the packages to update to, keyed by package name.
   *
   * @throws \InvalidArgumentException
   *   Thrown if no project version for Drupal core is provided.
   */
  public function begin(array $project_versions): void {
    if (count($project_versions) !== 1 || !array_key_exists('drupal', $project_versions)) {
      throw new \InvalidArgumentException("Currently only updates to Drupal core are supported.");
    }

    $composer = ComposerUtility::createForDirectory($this->pathLocator->getActiveDirectory());
    $package_versions = $this->getPackageVersions();

    foreach ($composer->getCorePackageNames() as $package) {
      $package_versions['production'][$package] = $project_versions['drupal'];
    }
    foreach ($composer->getCoreDevPackageNames() as $package) {
      $package_versions['dev'][$package] = $project_versions['drupal'];
    }
    $this->state->set(static::PACKAGES_KEY, $package_versions);
    $this->create();
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
    return $this->state->get(static::PACKAGES_KEY, [
      'production' => [],
      'dev' => [],
    ]);
  }

  /**
   * Stages the update.
   */
  public function stage(): void {
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

    $this->require($versions['production']);

    if ($versions['dev']) {
      $this->require($versions['dev'], TRUE);
    }
  }

  /**
   * Cleans the current update.
   */
  public function clean(): void {
    $this->destroy();
    $this->state->delete(static::PACKAGES_KEY);
  }

  /**
   * {@inheritdoc}
   */
  protected function dispatch(StageEvent $event): void {
    try {
      parent::dispatch($event);
    }
    catch (StageException $e) {
      throw new UpdateException($e->getResults(), $e->getMessage() ?: "Unable to complete the update because of errors.", $e->getCode(), $e);
    }
  }

}
