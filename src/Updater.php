<?php

namespace Drupal\automatic_updates;

use Drupal\automatic_updates\Event\PreCommitEvent;
use Drupal\automatic_updates\Event\PreStartEvent;
use Drupal\automatic_updates\Event\UpdateEvent;
use Drupal\automatic_updates\Exception\UpdateException;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\package_manager\ComposerUtility;
use Drupal\package_manager\PathLocator as PackageManagerPathLocator;
use Drupal\package_manager\Stage;
use Drupal\system\SystemManager;
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
   * @var \Drupal\package_manager\PathLocator
   */
  protected $pathLocator;

  /**
   * The stage service.
   *
   * @var \Drupal\package_manager\Stage
   */
  protected $stage;

  /**
   * Constructs an Updater object.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The string translation service.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher service.
   * @param \Drupal\package_manager\PathLocator $path_locator
   *   The path locator service.
   * @param \Drupal\package_manager\Stage $stage
   *   The stage service.
   */
  public function __construct(StateInterface $state, TranslationInterface $translation, EventDispatcherInterface $event_dispatcher, PackageManagerPathLocator $path_locator, Stage $stage) {
    $this->state = $state;
    $this->setStringTranslation($translation);
    $this->eventDispatcher = $event_dispatcher;
    $this->pathLocator = $path_locator;
    $this->stage = $stage;
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
    /** @var \Drupal\automatic_updates\Event\PreStartEvent $event */
    $event = $this->dispatchUpdateEvent(new PreStartEvent($composer, $packages), AutomaticUpdatesEvents::PRE_START);
    $this->stage->create($this->getExclusions($event));
    return $stage_key;
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
  private function getExclusions(UpdateEvent $event): array {
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
    $this->stage->require($current['package_versions']);
  }

  /**
   * Commits the current update.
   */
  public function commit(): void {
    $active_dir = $this->pathLocator->getActiveDirectory();
    $active_composer = ComposerUtility::createForDirectory($active_dir);

    $stage_dir = $this->pathLocator->getStageDirectory();
    $stage_composer = ComposerUtility::createForDirectory($stage_dir);

    /** @var \Drupal\automatic_updates\Event\PreCommitEvent $event */
    $event = $this->dispatchUpdateEvent(new PreCommitEvent($active_composer, $stage_composer), AutomaticUpdatesEvents::PRE_COMMIT);
    $this->stage->apply($this->getExclusions($event));
    $this->dispatchUpdateEvent(new UpdateEvent($active_composer), AutomaticUpdatesEvents::POST_COMMIT);
  }

  /**
   * Cleans the current update.
   */
  public function clean(): void {
    $this->stage->destroy();
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
