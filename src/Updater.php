<?php

namespace Drupal\automatic_updates;

use Composer\Autoload\ClassLoader;
use Drupal\automatic_updates\Event\PreCommitEvent;
use Drupal\automatic_updates\Event\PreStartEvent;
use Drupal\automatic_updates\Event\UpdateEvent;
use Drupal\automatic_updates\Exception\UpdateException;
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
   * The event classes to dispatch for various update events.
   *
   * @var string[]
   */
  protected const EVENT_CLASSES = [
    AutomaticUpdatesEvents::PRE_START => PreStartEvent::class,
    AutomaticUpdatesEvents::PRE_COMMIT => PreCommitEvent::class,
  ];

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
   */
  public function __construct(StateInterface $state, TranslationInterface $translation, BeginnerInterface $beginner, StagerInterface $stager, CleanerInterface $cleaner, CommitterInterface $committer, EventDispatcherInterface $event_dispatcher) {
    $this->state = $state;
    $this->beginner = $beginner;
    $this->stager = $stager;
    $this->cleaner = $cleaner;
    $this->committer = $committer;
    $this->setStringTranslation($translation);
    $this->eventDispatcher = $event_dispatcher;
  }

  /**
   * Gets the vendor directory.
   *
   * @return string
   *   The absolute path for vendor directory.
   */
  private static function getVendorDirectory(): string {
    $class_loader_reflection = new \ReflectionClass(ClassLoader::class);
    return dirname($class_loader_reflection->getFileName(), 2);
  }

  /**
   * Gets the stage directory.
   *
   * @return string
   *   The absolute path for stage directory.
   */
  public function getStageDirectory(): string {
    return realpath(static::getVendorDirectory() . '/..') . '/.automatic_updates_stage';
  }

  /**
   * Determines if there is an active update in progress.
   *
   * @return bool
   *   TRUE if there is active update, otherwise FALSE.
   */
  public function hasActiveUpdate(): bool {
    $staged_dir = $this->getStageDirectory();
    if (is_dir($staged_dir) || $this->state->get(static::STATE_KEY)) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Gets the active directory.
   *
   * @return string
   *   The absolute path for active directory.
   */
  public function getActiveDirectory(): string {
    return realpath(static::getVendorDirectory() . '/..');
  }

  /**
   * Begins the update.
   *
   * @return string
   *   A key for this stage update process.
   */
  public function begin(): string {
    $stage_key = $this->createActiveStage();
    $event = $this->dispatchUpdateEvent(AutomaticUpdatesEvents::PRE_START);
    $this->beginner->begin(static::getActiveDirectory(), static::getStageDirectory(), NULL, 120, $this->getExclusions($event));
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
  private function getExclusions($event): array {
    $make_relative = function (string $path): string {
      return str_replace($this->getActiveDirectory() . '/', '', $path);
    };
    return array_map($make_relative, $event->getExcludedPaths());
  }

  /**
   * Adds specific project versions to the staging area.
   *
   * @param string[] $project_versions
   *   The project versions to add to the staging area, keyed by package name.
   */
  public function stageVersions(array $project_versions): void {
    $packages = [];
    foreach ($project_versions as $project => $project_version) {
      if ($project === 'drupal') {
        // @todo Determine when to use drupal/core-recommended and when to use
        //   drupal/core
        $packages[] = "drupal/core:$project_version";
      }
      else {
        $packages[] = "drupal/$project:$project_version";
      }
    }
    $this->stagePackages($packages);
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
    // Store the expected packages to confirm no other Drupal packages were
    // updated.
    $current = $this->state->get(static::STATE_KEY);
    $current['packages'] = $packages;
    $this->state->set(self::STATE_KEY, $current);
  }

  /**
   * Commits the current update.
   */
  public function commit(): void {
    $this->dispatchUpdateEvent(AutomaticUpdatesEvents::PRE_COMMIT);
    // @todo Pass excluded paths into the committer once
    // https://github.com/php-tuf/composer-stager/pull/14 is in a tagged release
    // of Composer Stager.
    $this->committer->commit($this->getStageDirectory(), static::getActiveDirectory());
  }

  /**
   * Cleans the current update.
   */
  public function clean(): void {
    if (is_dir($this->getStageDirectory())) {
      $this->cleaner->clean($this->getStageDirectory());
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
    $this->stager->stage($command, $this->getStageDirectory());
  }

  /**
   * Initializes an active update and returns its ID.
   *
   * @return string
   *   The active update ID.
   */
  private function createActiveStage(): string {
    $value = static::STATE_KEY . microtime();
    $this->state->set(static::STATE_KEY, ['id' => $value]);
    return $value;
  }

  /**
   * Dispatches an update event.
   *
   * @param string $event_name
   *   The name of the event to dispatch.
   *
   * @return \Drupal\automatic_updates\Event\UpdateEvent
   *   The event object.
   *
   * @throws \Drupal\automatic_updates\Exception\UpdateException
   *   If any of the event subscribers adds a validation error.
   */
  public function dispatchUpdateEvent(string $event_name): UpdateEvent {
    $class = static::EVENT_CLASSES[$event_name] ?? UpdateEvent::class;
    $event = new $class();
    $this->eventDispatcher->dispatch($event, $event_name);
    if ($checker_results = $event->getResults(SystemManager::REQUIREMENT_ERROR)) {
      throw new UpdateException($checker_results,
        "Unable to complete the update because of errors.");
    }
    return $event;
  }

}
