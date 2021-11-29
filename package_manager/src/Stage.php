<?php

namespace Drupal\package_manager;

use Drupal\Component\Utility\Crypt;
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
 *
 * Only one staging area can exist at any given time, and the stage is owned by
 * the user or session that originally created it. Only the owner can perform
 * operations on the staging area, and the stage must be "claimed" by its owner
 * before any such operations are done. A stage is claimed by presenting a
 * unique token that is generated when the stage is created.
 */
class Stage {

  /**
   * The tempstore key under which to store the locking info for this stage.
   *
   * @var string
   */
  protected const TEMPSTORE_LOCK_KEY = 'lock';

  /**
   * The tempstore key under which to store arbitrary metadata for this stage.
   *
   * @var string
   */
  protected const TEMPSTORE_METADATA_KEY = 'metadata';

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
   * The shared temp store.
   *
   * @var \Drupal\Core\TempStore\SharedTempStore
   */
  protected $tempStore;

  /**
   * The lock info for the stage.
   *
   * Consists of a unique random string and the current class name.
   *
   * @var string[]
   */
  private $lock;

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
    return empty($this->tempStore->getMetadata(static::TEMPSTORE_LOCK_KEY));
  }

  /**
   * Returns a specific piece of metadata associated with this stage.
   *
   * Only the owner of the stage can access metadata, and the stage must either
   * be claimed by its owner, or created during the current request.
   *
   * @param string $key
   *   The metadata key.
   *
   * @return mixed
   *   The metadata value, or NULL if it is not set.
   */
  protected function getMetadata(string $key) {
    $this->checkOwnership();

    $metadata = $this->tempStore->getIfOwner(static::TEMPSTORE_METADATA_KEY) ?: [];
    return $metadata[$key] ?? NULL;
  }

  /**
   * Stores arbitrary metadata associated with this stage.
   *
   * Only the owner of the stage can set metadata, and the stage must either be
   * claimed by its owner, or created during the current request.
   *
   * @param string $key
   *   The key under which to store the metadata.
   * @param mixed $data
   *   The metadata to store.
   */
  protected function setMetadata(string $key, $data): void {
    $this->checkOwnership();

    $metadata = $this->tempStore->get(static::TEMPSTORE_METADATA_KEY);
    $metadata[$key] = $data;
    $this->tempStore->set(static::TEMPSTORE_METADATA_KEY, $metadata);
  }

  /**
   * Copies the active code base into the staging area.
   *
   * This will automatically claim the stage, so external code does NOT need to
   * call ::claim(). However, if it was created during another request, the
   * stage must be claimed before operations can be performed on it.
   *
   * @return string
   *   Unique ID for the stage, which can be used to claim the stage before
   *   performing other operations on it. Calling code should store this ID for
   *   as long as the stage needs to exist.
   *
   * @see ::claim()
   */
  public function create(): string {
    if (!$this->isAvailable()) {
      throw new StageException([], 'Cannot create a new stage because one already exists.');
    }
    // Mark the stage as unavailable as early as possible, before dispatching
    // the pre-create event. The idea is to prevent a race condition if the
    // event subscribers take a while to finish, and two different users attempt
    // to create a staging area at around the same time. If an error occurs
    // while the event is being processed, the stage is marked as available.
    // @see ::dispatch()
    $id = Crypt::randomBytesBase64();
    $this->tempStore->set(static::TEMPSTORE_LOCK_KEY, [$id, static::class]);
    $this->claim($id);

    $active_dir = $this->pathLocator->getActiveDirectory();
    $stage_dir = $this->pathLocator->getStageDirectory();

    $event = new PreCreateEvent($this);
    $this->dispatch($event);

    $this->beginner->begin($active_dir, $stage_dir, $event->getExcludedPaths());
    $this->dispatch(new PostCreateEvent($this));
    return $id;
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
    $this->markAsAvailable();
    $this->dispatch(new PostDestroyEvent($this));
  }

  /**
   * Marks the stage as available.
   */
  protected function markAsAvailable(): void {
    $this->tempStore->delete(static::TEMPSTORE_METADATA_KEY);
    $this->tempStore->delete(static::TEMPSTORE_LOCK_KEY);
    $this->lock = NULL;
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

      $results = $event->getResults();
      if ($results) {
        throw new StageException($results);
      }
    }
    catch (\Throwable $error) {
      // If we are not going to be able to create the staging area, mark it as
      // available.
      // @see ::create()
      if ($event instanceof PreCreateEvent) {
        $this->markAsAvailable();
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
   * Attempts to claim the stage.
   *
   * Once a stage has been created, no operations can be performed on it until
   * it is claimed. This is to ensure that stage operations across multiple
   * requests are being done by the same code, running under the same user or
   * session that created the stage in the first place. To claim a stage, the
   * calling code must provide the unique identifier that was generated when the
   * stage was created.
   *
   * The stage is claimed when it is created, so external code does NOT need to
   * call this method after calling ::create() in the same request.
   *
   * @param string $unique_id
   *   The unique ID that was returned by ::create().
   *
   * @return $this
   *
   * @throws \Drupal\package_manager\StageException
   *   If the stage cannot be claimed. This can happen if the current user or
   *   session did not originally create the stage, if $unique_id doesn't match
   *   the unique ID that was generated when the stage was created, or the
   *   current class is not the same one that was used to create the stage.
   *
   * @see ::create()
   */
  final public function claim(string $unique_id): self {
    if ($this->isAvailable()) {
      throw new StageException([], 'Cannot claim the stage because no stage has been created.');
    }

    $stored_lock = $this->tempStore->getIfOwner(self::TEMPSTORE_LOCK_KEY);
    if (!$stored_lock) {
      throw new StageException([], 'Cannot claim the stage because it is not owned by the current user or session.');
    }

    if ($stored_lock === [$unique_id, static::class]) {
      $this->lock = $stored_lock;
      return $this;
    }
    throw new StageException([], 'Cannot claim the stage because the current lock does not match the stored lock.');
  }

  /**
   * Ensures that the current user or session owns the staging area.
   *
   * @throws \LogicException
   *   If ::claim() has not been previously called.
   * @throws \Drupal\package_manager\StageException
   *   If the current user or session does not own the staging area.
   */
  final protected function checkOwnership(): void {
    if (empty($this->lock)) {
      throw new \LogicException('Stage must be claimed before performing any operations on it.');
    }

    $stored_lock = $this->tempStore->getIfOwner(static::TEMPSTORE_LOCK_KEY);
    if ($stored_lock !== $this->lock) {
      throw new StageException([], 'Stage is not owned by the current user or session.');
    }
  }

}
