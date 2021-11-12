<?php

namespace Drupal\package_manager;

use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\package_manager\Event\PostApplyEvent;
use Drupal\package_manager\Event\PostCreateEvent;
use Drupal\package_manager\Event\PostDestroyEvent;
use Drupal\package_manager\Event\PostRequireEvent;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreDestroyEvent;
use Drupal\package_manager\Event\PreRequireEvent;
use Drupal\package_manager\Event\StageEvent;
use Drupal\system\SystemManager;
use PhpTuf\ComposerStager\Domain\BeginnerInterface;
use PhpTuf\ComposerStager\Domain\CleanerInterface;
use PhpTuf\ComposerStager\Domain\CommitterInterface;
use PhpTuf\ComposerStager\Domain\StagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Creates and manages a staging area in which to install or update code.
 *
 * Allows calling code to copy the current Drupal site into a temporary staging
 * directory, use Composer to require packages into it, sync changes from the
 * staging directory back into the active code base, and then delete the
 * staging directory.
 */
class Stage {

  /**
   * The path locator service.
   *
   * @var \Drupal\package_manager\PathLocator
   */
  protected $pathLocator;

  /**
   * The beginner service from Composer Stager.
   *
   * @var \PhpTuf\ComposerStager\Domain\BeginnerInterface
   */
  protected $beginner;

  /**
   * The stager service from Composer Stager.
   *
   * @var \PhpTuf\ComposerStager\Domain\StagerInterface
   */
  protected $stager;

  /**
   * The committer service from Composer Stager.
   *
   * @var \PhpTuf\ComposerStager\Domain\CommitterInterface
   */
  protected $committer;

  /**
   * The cleaner service from Composer Stager.
   *
   * @var \PhpTuf\ComposerStager\Domain\CleanerInterface
   */
  protected $cleaner;

  /**
   * The event dispatcher service.
   *
   * @var \Symfony\Contracts\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;


  /**
   * The tempstore key under which to store the active status of this stage.
   *
   * @var string
   */
  protected const TEMPSTORE_ACTIVE_KEY = 'active';

  /**
   * The shared temp store.
   *
   * @var \Drupal\Core\TempStore\SharedTempStore
   */
  protected $tempStore;

  /**
   * Constructs a new Stage object.
   *
   * @param \Drupal\package_manager\PathLocator $path_locator
   *   The path locator service.
   * @param \PhpTuf\ComposerStager\Domain\BeginnerInterface $beginner
   *   The beginner service from Composer Stager.
   * @param \PhpTuf\ComposerStager\Domain\StagerInterface $stager
   *   The stager service from Composer Stager.
   * @param \PhpTuf\ComposerStager\Domain\CommitterInterface $committer
   *   The committer service from Composer Stager.
   * @param \PhpTuf\ComposerStager\Domain\CleanerInterface $cleaner
   *   The cleaner service from Composer Stager.
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher service.
   * @param \Drupal\Core\TempStore\SharedTempStoreFactory $shared_tempstore
   *   The shared tempstore factory.
   */
  public function __construct(PathLocator $path_locator, BeginnerInterface $beginner, StagerInterface $stager, CommitterInterface $committer, CleanerInterface $cleaner, EventDispatcherInterface $event_dispatcher, SharedTempStoreFactory $shared_tempstore) {
    $this->pathLocator = $path_locator;
    $this->beginner = $beginner;
    $this->stager = $stager;
    $this->committer = $committer;
    $this->cleaner = $cleaner;
    $this->eventDispatcher = $event_dispatcher;
    $this->tempStore = $shared_tempstore->get('package_manager_stage');
  }

  /**
   * Determines if the staging area can be created.
   *
   * @return bool
   *   TRUE if the staging area can be created, otherwise FALSE.
   */
  final public function isAvailable(): bool {
    return empty($this->tempStore->getMetadata(static::TEMPSTORE_ACTIVE_KEY));
  }

  /**
   * Determines if the current user or session is the owner of the staging area.
   *
   * @return bool
   *   TRUE if the current session or user is the owner of the staging area,
   *   otherwise FALSE.
   */
  final public function isOwnedByCurrentUser(): bool {
    return !empty($this->tempStore->getIfOwner(static::TEMPSTORE_ACTIVE_KEY));
  }

  /**
   * Copies the active code base into the staging area.
   */
  public function create(): void {
    if (!$this->isAvailable()) {
      throw new StageException([], 'Cannot create a new stage because one already exists.');
    }
    // Mark the stage as unavailable as early as possible, before dispatching
    // the pre-create event. The idea is to prevent a race condition if the
    // event subscribers take a while to finish, and two different users attempt
    // to create a staging area at around the same time. If an error occurs
    // while the event is being processed, the stage is marked as available.
    // @see ::dispatch()
    $this->tempStore->set(static::TEMPSTORE_ACTIVE_KEY, TRUE);

    $active_dir = $this->pathLocator->getActiveDirectory();
    $stage_dir = $this->pathLocator->getStageDirectory();

    $event = new PreCreateEvent($this);
    $this->dispatch($event);

    $this->beginner->begin($active_dir, $stage_dir, $event->getExcludedPaths());
    $this->dispatch(new PostCreateEvent($this));
  }

  /**
   * Requires packages in the staging area.
   *
   * @param string[] $constraints
   *   The packages to require, in the form 'vendor/name:version'.
   * @param bool $dev
   *   (optional) Whether the packages should be required as dev dependencies.
   *   Defaults to FALSE.
   */
  public function require(array $constraints, bool $dev = FALSE): void {
    $this->checkOwnership();

    $command = array_merge(['require'], $constraints);
    $command[] = '--update-with-all-dependencies';
    if ($dev) {
      $command[] = '--dev';
    }

    $this->dispatch(new PreRequireEvent($this));
    $this->stager->stage($command, $this->pathLocator->getStageDirectory());
    $this->dispatch(new PostRequireEvent($this));
  }

  /**
   * Applies staged changes to the active directory.
   */
  public function apply(): void {
    $this->checkOwnership();

    $active_dir = $this->pathLocator->getActiveDirectory();
    $stage_dir = $this->pathLocator->getStageDirectory();

    $event = new PreApplyEvent($this);
    $this->dispatch($event);

    $this->committer->commit($stage_dir, $active_dir, $event->getExcludedPaths());
    $this->dispatch(new PostApplyEvent($this));
  }

  /**
   * Deletes the staging area.
   *
   * @param bool $force
   *   (optional) If TRUE, the staging area will be destroyed even if it is not
   *   owned by the current user or session. Defaults to FALSE.
   *
   * @todo Do not allow the stage to be destroyed while it's being applied to
   *   the active directory in https://www.drupal.org/i/3248909.
   */
  public function destroy(bool $force = FALSE): void {
    if (!$force) {
      $this->checkOwnership();
    }

    $this->dispatch(new PreDestroyEvent($this));
    $stage_dir = $this->pathLocator->getStageDirectory();
    if (is_dir($stage_dir)) {
      $this->cleaner->clean($stage_dir);
    }
    // We're all done, so mark the stage as available.
    $this->tempStore->delete(static::TEMPSTORE_ACTIVE_KEY);
    $this->dispatch(new PostDestroyEvent($this));
  }

  /**
   * Dispatches an event and handles any errors that it collects.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   *
   * @throws \Drupal\package_manager\StageException
   *   If the event collects any validation errors, or a subscriber throws a
   *   StageException directly.
   * @throws \RuntimeException
   *   If any other sort of error occurs.
   */
  protected function dispatch(StageEvent $event): void {
    try {
      $this->eventDispatcher->dispatch($event);

      $errors = $event->getResults(SystemManager::REQUIREMENT_ERROR);
      if ($errors) {
        throw new StageException($errors);
      }
    }
    catch (\Throwable $error) {
      // If we are not going to be able to create the staging area, mark it as
      // available.
      // @see ::create()
      if ($event instanceof PreCreateEvent) {
        $this->tempStore->delete(static::TEMPSTORE_ACTIVE_KEY);
      }

      // Wrap the exception to preserve the backtrace, and re-throw it.
      if ($error instanceof StageException) {
        throw new StageException($error->getResults(), $error->getMessage(), $error->getCode(), $error);
      }
      else {
        throw new \RuntimeException($error->getMessage(), $error->getCode(), $error);
      }
    }
  }

  /**
   * Returns a Composer utility object for the active directory.
   *
   * @return \Drupal\package_manager\ComposerUtility
   *   The Composer utility object.
   */
  public function getActiveComposer(): ComposerUtility {
    $dir = $this->pathLocator->getActiveDirectory();
    return ComposerUtility::createForDirectory($dir);
  }

  /**
   * Returns a Composer utility object for the stage directory.
   *
   * @return \Drupal\package_manager\ComposerUtility
   *   The Composer utility object.
   */
  public function getStageComposer(): ComposerUtility {
    $dir = $this->pathLocator->getStageDirectory();
    return ComposerUtility::createForDirectory($dir);
  }

  /**
   * Ensures that the current user or session owns the staging area.
   *
   * @throws \Drupal\package_manager\StageException
   *   If the current user or session does not own the staging area.
   */
  protected function checkOwnership(): void {
    if (!$this->isOwnedByCurrentUser()) {
      throw new StageException([], 'Stage is not owned by the current user or session.');
    }
  }

}
