<?php

namespace Drupal\automatic_updates;

use Drupal\automatic_updates\Event\PreCommitEvent;
use Drupal\automatic_updates\Event\PreStartEvent;
use Drupal\automatic_updates\Event\UpdateEvent;
use Drupal\automatic_updates\Exception\UpdateException;
use Drupal\Component\Serialization\Json;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\system\SystemManager;
use PhpTuf\ComposerStager\Domain\BeginnerInterface;
use PhpTuf\ComposerStager\Domain\CleanerInterface;
use PhpTuf\ComposerStager\Domain\CommitterInterface;
use PhpTuf\ComposerStager\Domain\StagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Defines a service to perform updates.
 */
class Updater {

  use StringTranslationTrait;

  /**
   * The state key in which to store the status of the update.
   *
   * @var string
   */
  public const STATE_KEY = 'AUTOMATIC_UPDATES_CURRENT';

  /**
   * The composer_stager beginner service.
   *
   * @var \Drupal\automatic_updates\ComposerStager\Beginner
   */
  protected $beginner;

  /**
   * The composer_stager stager service.
   *
   * @var \PhpTuf\ComposerStager\Domain\StagerInterface
   */
  protected $stager;

  /**
   * The composer_stager cleaner service.
   *
   * @var \PhpTuf\ComposerStager\Domain\CleanerInterface
   */
  protected $cleaner;

  /**
   * The composer_stager committer service.
   *
   * @var \PhpTuf\ComposerStager\Domain\CommitterInterface
   */
  protected $committer;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The event dispatcher service.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The path locator service.
   *
   * @var \Drupal\automatic_updates\PathLocator
   */
  protected $pathLocator;

  /**
   * Constructs an Updater object.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The string translation service.
   * @param \PhpTuf\ComposerStager\Domain\BeginnerInterface $beginner
   *   The Composer Stager's beginner service.
   * @param \PhpTuf\ComposerStager\Domain\StagerInterface $stager
   *   The Composer Stager's stager service.
   * @param \PhpTuf\ComposerStager\Domain\CleanerInterface $cleaner
   *   The Composer Stager's cleaner service.
   * @param \PhpTuf\ComposerStager\Domain\CommitterInterface $committer
   *   The Composer Stager's committer service.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher service.
   * @param \Drupal\automatic_updates\PathLocator $path_locator
   *   The path locator service.
   */
  public function __construct(StateInterface $state, TranslationInterface $translation, BeginnerInterface $beginner, StagerInterface $stager, CleanerInterface $cleaner, CommitterInterface $committer, EventDispatcherInterface $event_dispatcher, PathLocator $path_locator) {
    $this->state = $state;
    $this->beginner = $beginner;
    $this->stager = $stager;
    $this->cleaner = $cleaner;
    $this->committer = $committer;
    $this->setStringTranslation($translation);
    $this->eventDispatcher = $event_dispatcher;
    $this->pathLocator = $path_locator;
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
    $packages = [
      $this->getCorePackageName() => $project_versions['drupal'],
    ];
    $stage_key = $this->createActiveStage($packages);
    /** @var \Drupal\automatic_updates\Event\PreStartEvent $event */
    $event = $this->dispatchUpdateEvent(new PreStartEvent($packages), AutomaticUpdatesEvents::PRE_START);
    $this->beginner->begin($this->pathLocator->getActiveDirectory(), $this->pathLocator->getStageDirectory(), $this->getExclusions($event));
    return $stage_key;
  }

  /**
   * Determines the name of the core package in the project composer.json.
   *
   * This makes the following assumptions:
   * - The vendor directory is next to the project composer.json.
   * - The project composer.json contains a requirement for a core package.
   * - That requirement is either for drupal/core or drupal/core-recommended.
   *
   * @return string
   *   The name of the core package (either drupal/core or
   *   drupal/core-recommended).
   *
   * @throws \RuntimeException
   *   If the project composer.json is not found.
   * @throws \LogicException
   *   If the project composer.json does not contain one of the supported core
   *   packages.
   *
   * @todo Move this to an update validator, or use a more robust method of
   *   detecting the core package.
   */
  public function getCorePackageName(): string {
    $composer = realpath($this->pathLocator->getProjectRoot() . '/composer.json');

    if (empty($composer) || !file_exists($composer)) {
      throw new \RuntimeException("Could not find project-level composer.json");
    }

    $composer = file_get_contents($composer);
    $composer = Json::decode($composer);

    if (isset($composer['require']['drupal/core'])) {
      return 'drupal/core';
    }
    elseif (isset($composer['require']['drupal/core-recommended'])) {
      return 'drupal/core-recommended';
    }
    throw new \LogicException("Could not determine the Drupal core package in the project-level composer.json.");
  }

  /**
   * Gets the excluded paths collected by an event object.
   *
   * @param \Drupal\automatic_updates\Event\PreStartEvent|\Drupal\automatic_updates\Event\PreCommitEvent $event
   *   The event object.
   *
   * @return string[]
   *   The paths to exclude, relative to the active directory.
   */
  private function getExclusions($event): array {
    $make_relative = function (string $path): string {
      return str_replace($this->pathLocator->getActiveDirectory() . '/', '', $path);
    };
    return array_map($make_relative, $event->getExcludedPaths());
  }

  /**
   * Stages the update.
   */
  public function stage(): void {
    $current = $this->state->get(static::STATE_KEY);
    $this->stagePackages($current['package_versions']);
  }

  /**
   * Installs Composer packages in the staging area.
   *
   * @param string[] $packages
   *   The versions of the packages to stage, keyed by package name.
   */
  protected function stagePackages(array $packages): void {
    $command = array_merge(['require'], $packages);
    $command[] = '--update-with-all-dependencies';
    $this->stageCommand($command);
  }

  /**
   * Commits the current update.
   */
  public function commit(): void {
    /** @var \Drupal\automatic_updates\Event\PreCommitEvent $event */
    $event = $this->dispatchUpdateEvent(new PreCommitEvent(), AutomaticUpdatesEvents::PRE_COMMIT);
    $this->committer->commit($this->pathLocator->getStageDirectory(), $this->pathLocator->getActiveDirectory(), $this->getExclusions($event));
    $this->dispatchUpdateEvent(new UpdateEvent(), AutomaticUpdatesEvents::POST_COMMIT);
  }

  /**
   * Cleans the current update.
   */
  public function clean(): void {
    $stage_dir = $this->pathLocator->getStageDirectory();
    if (is_dir($stage_dir)) {
      $this->cleaner->clean($stage_dir);
    }
    $this->state->delete(static::STATE_KEY);
  }

  /**
   * Stages a Composer command.
   *
   * @param string[] $command
   *   The command array as expected by
   *   \PhpTuf\ComposerStager\Domain\StagerInterface::stage().
   *
   * @see \PhpTuf\ComposerStager\Domain\StagerInterface::stage()
   */
  protected function stageCommand(array $command): void {
    $this->stager->stage($command, $this->pathLocator->getStageDirectory());
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
    $requirements = [];
    foreach ($package_versions as $package_name => $version) {
      $requirements[] = "$package_name:$version";
    }

    $value = static::STATE_KEY . microtime();
    $this->state->set(
      static::STATE_KEY,
      [
        'id' => $value,
        'package_versions' => $requirements,
      ]
    );
    return $value;
  }

  /**
   * Dispatches an update event.
   *
   * @param \Drupal\automatic_updates\Event\UpdateEvent $event
   *   The update event.
   * @param string $event_name
   *   The name of the event to dispatch.
   *
   * @return \Drupal\automatic_updates\Event\UpdateEvent
   *   The event object.
   *
   * @throws \Drupal\automatic_updates\Exception\UpdateException
   *   If any of the event subscribers adds a validation error.
   */
  public function dispatchUpdateEvent(UpdateEvent $event, string $event_name): UpdateEvent {
    $this->eventDispatcher->dispatch($event, $event_name);
    if ($checker_results = $event->getResults(SystemManager::REQUIREMENT_ERROR)) {
      throw new UpdateException($checker_results,
        "Unable to complete the update because of errors.");
    }
    return $event;
  }

}
