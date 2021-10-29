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
   * The state key in which to store the status of the update.
   *
   * @var string
   */
  public const STATE_KEY = 'AUTOMATIC_UPDATES_CURRENT';

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
    if (is_dir($staged_dir) || $this->state->get(static::STATE_KEY)) {
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
   * @return string
   *   A key for this stage update process.
   *
   * @throws \InvalidArgumentException
   *   Thrown if no project version for Drupal core is provided.
   */
  public function begin(array $project_versions): string {
    if (count($project_versions) !== 1 || !array_key_exists('drupal', $project_versions)) {
      throw new \InvalidArgumentException("Currently only updates to Drupal core are supported.");
    }

    $composer = ComposerUtility::createForDirectory($this->pathLocator->getActiveDirectory());
    $packages = [];
    foreach ($composer->getCorePackageNames() as $package) {
      $packages[$package] = $project_versions['drupal'];
    }
    $stage_key = $this->createActiveStage($packages);
    $this->create();
    return $stage_key;
  }

  /**
   * Returns the package versions that will be required during the update.
   *
   * @return string[]
   *   The package versions, as a set of Composer constraints where the keys are
   *   the package names, and the values are the version constraints.
   */
  public function getPackageVersions(): array {
    $metadata = $this->state->get(static::STATE_KEY, []);
    return $metadata['package_versions'];
  }

  /**
   * Stages the update.
   */
  public function stage(): void {
    $metadata = $this->state->get(static::STATE_KEY, []);

    $requirements = [];
    foreach ($metadata['package_versions'] as $package => $constraint) {
      $requirements[] = "$package:$constraint";
    }
    $this->require($requirements);
  }

  /**
   * Commits the current update.
   */
  public function commit(): void {
    $this->apply();
  }

  /**
   * Cleans the current update.
   */
  public function clean(): void {
    $this->destroy();
    $this->state->delete(static::STATE_KEY);
  }

  /**
   * Initializes an active update and returns its ID.
   *
   * @param string[] $package_versions
   *   The versions of the packages to stage, keyed by package name.
   *
   * @return string
   *   The active update ID.
   */
  private function createActiveStage(array $package_versions): string {
    $value = static::STATE_KEY . microtime();

    $this->state->set(static::STATE_KEY, [
      'id' => $value,
      'package_versions' => $package_versions,
    ]);
    return $value;
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
