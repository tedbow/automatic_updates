<?php

namespace Drupal\package_manager;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\package_manager\Event\PostApplyEvent;
use Drupal\package_manager\Event\PostCreateEvent;
use Drupal\package_manager\Event\PostDestroyEvent;
use Drupal\package_manager\Event\PostRequireEvent;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreDestroyEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;
use Drupal\package_manager\Event\PreRequireEvent;
use Drupal\package_manager\Event\StageEvent;
use Drupal\package_manager\Exception\StageException;
use Drupal\package_manager\Exception\StageOwnershipException;
use Drupal\package_manager\Exception\StageValidationException;
use PhpTuf\ComposerStager\Domain\Core\Beginner\BeginnerInterface;
use PhpTuf\ComposerStager\Domain\Core\Committer\CommitterInterface;
use PhpTuf\ComposerStager\Domain\Core\Stager\StagerInterface;
use PhpTuf\ComposerStager\Infrastructure\Factory\Path\PathFactory;
use PhpTuf\ComposerStager\Infrastructure\Value\PathList\PathList;
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
 *
 * Although a site can only have one staging area, it is possible for privileged
 * users to destroy a stage created by another user. To prevent such actions
 * from putting the file system into an uncertain state (for example, if a stage
 * is destroyed by another user while it is still being created), the staging
 * directory has a randomly generated name. For additional cleanliness, all
 * staging directories created by a specific site live in a single directory,
 * called the "staging root" and identified by the UUID of the current site
 * (e.g. `/tmp/.package_managerSITE_UUID`), which is deleted when any stage
 * created by that site is destroyed.
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
   * The tempstore key under which to store the path of the staging root.
   *
   * @var string
   *
   * @see ::getStagingRoot()
   */
  private const TEMPSTORE_STAGING_ROOT_KEY = 'staging_root';

  /**
   * The tempstore key under which to store the time that ::apply() was called.
   *
   * @var string
   *
   * @see ::apply()
   * @see ::destroy()
   */
  private const TEMPSTORE_APPLY_TIME_KEY = 'apply_time';

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The path locator service.
   *
   * @var \Drupal\package_manager\PathLocator
   */
  protected $pathLocator;

  /**
   * The beginner service.
   *
   * @var \PhpTuf\ComposerStager\Domain\Core\Beginner\BeginnerInterface
   */
  protected $beginner;

  /**
   * The stager service.
   *
   * @var \PhpTuf\ComposerStager\Domain\Core\Stager\StagerInterface
   */
  protected $stager;

  /**
   * The committer service.
   *
   * @var \PhpTuf\ComposerStager\Domain\Core\Committer\CommitterInterface
   */
  protected $committer;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

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
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The path factory service.
   *
   * @var \PhpTuf\ComposerStager\Infrastructure\Factory\Path\PathFactoryInterface
   */
  protected $pathFactory;

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
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\package_manager\PathLocator $path_locator
   *   The path locator service.
   * @param \PhpTuf\ComposerStager\Domain\Core\Beginner\BeginnerInterface $beginner
   *   The beginner service.
   * @param \PhpTuf\ComposerStager\Domain\Core\Stager\StagerInterface $stager
   *   The stager service.
   * @param \PhpTuf\ComposerStager\Domain\Core\Committer\CommitterInterface $committer
   *   The committer service.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher service.
   * @param \Drupal\Core\TempStore\SharedTempStoreFactory $shared_tempstore
   *   The shared tempstore factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, PathLocator $path_locator, BeginnerInterface $beginner, StagerInterface $stager, CommitterInterface $committer, FileSystemInterface $file_system, EventDispatcherInterface $event_dispatcher, SharedTempStoreFactory $shared_tempstore, TimeInterface $time) {
    $this->configFactory = $config_factory;
    $this->pathLocator = $path_locator;
    $this->beginner = $beginner;
    $this->stager = $stager;
    $this->committer = $committer;
    $this->fileSystem = $file_system;
    $this->eventDispatcher = $event_dispatcher;
    $this->time = $time;
    $this->tempStore = $shared_tempstore->get('package_manager_stage');
    $this->pathFactory = new PathFactory();
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
   * @param int|null $timeout
   *   (optional) How long to allow the file copying operation to run before
   *   timing out, in seconds, or NULL to never time out. Defaults to 300
   *   seconds.
   *
   * @return string
   *   Unique ID for the stage, which can be used to claim the stage before
   *   performing other operations on it. Calling code should store this ID for
   *   as long as the stage needs to exist.
   *
   * @throws \Drupal\package_manager\Exception\StageException
   *   Thrown if a staging area already exists.
   *
   * @see ::claim()
   */
  public function create(?int $timeout = 300): string {
    if (!$this->isAvailable()) {
      throw new StageException('Cannot create a new stage because one already exists.');
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

    $active_dir = $this->pathFactory->create($this->pathLocator->getProjectRoot());
    $stage_dir = $this->pathFactory->create($this->getStageDirectory());

    $event = new PreCreateEvent($this);
    // If an error occurs and we won't be able to create the stage, mark it as
    // available.
    $this->dispatch($event, [$this, 'markAsAvailable']);

    $this->beginner->begin($active_dir, $stage_dir, new PathList($event->getExcludedPaths()), NULL, $timeout);
    $this->dispatch(new PostCreateEvent($this));
    return $id;
  }

  /**
   * Adds or updates packages in the staging area.
   *
   * @param string[] $runtime
   *   The packages to add as regular top-level dependencies, in the form
   *   'vendor/name:version'.
   * @param string[] $dev
   *   (optional) The packages to add as dev dependencies, in the form
   *   'vendor/name:version'. Defaults to an empty array.
   */
  public function require(array $runtime, array $dev = []): void {
    $this->checkOwnership();

    $this->dispatch(new PreRequireEvent($this, $runtime, $dev));
    $active_dir = $this->pathFactory->create($this->pathLocator->getProjectRoot());
    $stage_dir = $this->pathFactory->create($this->getStageDirectory());

    // Change the runtime and dev requirements as needed, but don't update
    // the installed packages yet.
    if ($runtime) {
      $command = array_merge(['require', '--no-update'], $runtime);
      $this->stager->stage($command, $active_dir, $stage_dir);
    }
    if ($dev) {
      $command = array_merge(['require', '--dev', '--no-update'], $dev);
      $this->stager->stage($command, $active_dir, $stage_dir);
    }

    // If constraints were changed, update those packages.
    if ($runtime || $dev) {
      $command = array_merge(['update', '--with-all-dependencies'], $runtime, $dev);
      $this->stager->stage($command, $active_dir, $stage_dir);
    }

    $this->dispatch(new PostRequireEvent($this, $runtime, $dev));
  }

  /**
   * Applies staged changes to the active directory.
   *
   * After the staged changes are applied, the current request should be
   * terminated as soon as possible. This is because the code loaded into the
   * PHP runtime may no longer match the code that is physically present in the
   * active code base, which means that the current request is running in an
   * unreliable, inconsistent environment. In the next request,
   * ::postApply() should be called as early as possible after Drupal is
   * fully bootstrapped, to rebuild the service container, flush caches, and
   * dispatch the post-apply event.
   *
   * @param int|null $timeout
   *   (optional) How long to allow the file copying operation to run before
   *   timing out, in seconds, or NULL to never time out. Defaults to 600
   *   seconds.
   */
  public function apply(?int $timeout = 600): void {
    $this->checkOwnership();

    $active_dir = $this->pathFactory->create($this->pathLocator->getProjectRoot());
    $stage_dir = $this->pathFactory->create($this->getStageDirectory());

    // If an error occurs while dispatching the events, ensure that ::destroy()
    // doesn't think we're in the middle of applying the staged changes to the
    // active directory.
    $event = new PreApplyEvent($this);
    $this->tempStore->set(self::TEMPSTORE_APPLY_TIME_KEY, $this->time->getRequestTime());
    $this->dispatch($event, $this->setNotApplying());

    $this->committer->commit($stage_dir, $active_dir, new PathList($event->getExcludedPaths()), NULL, $timeout);
  }

  /**
   * Returns a closure that marks this stage as no longer being applied.
   *
   * @return \Closure
   *   A closure that, when called, marks this stage as no longer in the process
   *   of being applied to the active directory.
   */
  private function setNotApplying(): \Closure {
    return function (): void {
      $this->tempStore->delete(self::TEMPSTORE_APPLY_TIME_KEY);
    };
  }

  /**
   * Performs post-apply tasks.
   */
  public function postApply(): void {
    $this->checkOwnership();

    // Rebuild the container and clear all caches, to ensure that new services
    // are picked up.
    drupal_flush_all_caches();
    // Refresh the event dispatcher so that new or changed event subscribers
    // will be called. The other services we depend on are either stateless or
    // unlikely to call newly added code during the current request.
    $this->eventDispatcher = \Drupal::service('event_dispatcher');

    $release_apply = $this->setNotApplying();
    $this->dispatch(new PostApplyEvent($this), $release_apply);
    $release_apply();
  }

  /**
   * Deletes the staging area.
   *
   * @param bool $force
   *   (optional) If TRUE, the staging area will be destroyed even if it is not
   *   owned by the current user or session. Defaults to FALSE.
   *
   * @throws \Drupal\package_manager\Exception\StageException
   *   If the staged changes are being applied to the active directory.
   */
  public function destroy(bool $force = FALSE): void {
    if (!$force) {
      $this->checkOwnership();
    }
    if ($this->isApplying()) {
      throw new StageException('Cannot destroy the staging area while it is being applied to the active directory.');
    }

    $this->dispatch(new PreDestroyEvent($this));
    $staging_root = $this->getStagingRoot();
    // If the staging root exists, delete it and everything in it.
    if (file_exists($staging_root)) {
      try {
        $this->fileSystem->deleteRecursive($staging_root, function (string $path): void {
          $this->fileSystem->chmod($path, 0777);
        });
      }
      catch (FileException $e) {
        // Deliberately swallow the exception so that the stage will be marked
        // as available and the post-destroy event will be fired, even if the
        // staging area can't actually be deleted. The file system service logs
        // the exception, so we don't need to do anything else here.
      }
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
    $this->tempStore->delete(self::TEMPSTORE_STAGING_ROOT_KEY);
    $this->lock = NULL;
  }

  /**
   * Dispatches an event and handles any errors that it collects.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   * @param callable $on_error
   *   (optional) A callback function to call if an error occurs, before any
   *   exceptions are thrown.
   *
   * @throws \Drupal\package_manager\Exception\StageValidationException
   *   If the event collects any validation errors.
   * @throws \Drupal\package_manager\Exception\StageException
   *   If any other sort of error occurs.
   */
  protected function dispatch(StageEvent $event, callable $on_error = NULL): void {
    try {
      $this->eventDispatcher->dispatch($event);

      if ($event instanceof PreOperationStageEvent) {
        $results = $event->getResults();
        if ($results) {
          $error = new StageValidationException($results);
        }
      }
    }
    catch (\Throwable $error) {
      $error = new StageException($error->getMessage(), $error->getCode(), $error);
    }

    if (isset($error)) {
      if ($on_error) {
        $on_error();
      }
      throw $error;
    }
  }

  /**
   * Returns a Composer utility object for the active directory.
   *
   * @return \Drupal\package_manager\ComposerUtility
   *   The Composer utility object.
   */
  public function getActiveComposer(): ComposerUtility {
    $dir = $this->pathLocator->getProjectRoot();
    return ComposerUtility::createForDirectory($dir);
  }

  /**
   * Returns a Composer utility object for the stage directory.
   *
   * @return \Drupal\package_manager\ComposerUtility
   *   The Composer utility object.
   */
  public function getStageComposer(): ComposerUtility {
    $dir = $this->getStageDirectory();
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
   * @throws \Drupal\package_manager\Exception\StageException
   *   If the stage cannot be claimed. This can happen if the current user or
   *   session did not originally create the stage, if $unique_id doesn't match
   *   the unique ID that was generated when the stage was created, or the
   *   current class is not the same one that was used to create the stage.
   *
   * @see ::create()
   */
  final public function claim(string $unique_id): self {
    if ($this->isAvailable()) {
      throw new StageException('Cannot claim the stage because no stage has been created.');
    }

    $stored_lock = $this->tempStore->getIfOwner(self::TEMPSTORE_LOCK_KEY);
    if (!$stored_lock) {
      throw new StageOwnershipException('Cannot claim the stage because it is not owned by the current user or session.');
    }

    if ($stored_lock === [$unique_id, static::class]) {
      $this->lock = $stored_lock;
      return $this;
    }
    throw new StageOwnershipException('Cannot claim the stage because the current lock does not match the stored lock.');
  }

  /**
   * Validates the ownership of staging area.
   *
   * The stage is considered under valid ownership if it was created by current
   * user or session, using the current class.
   *
   * @throws \LogicException
   *   If ::claim() has not been previously called.
   * @throws \Drupal\package_manager\Exception\StageOwnershipException
   *   If the current user or session does not own the staging area, or it was
   *   created by a different class.
   */
  final protected function checkOwnership(): void {
    if (empty($this->lock)) {
      throw new \LogicException('Stage must be claimed before performing any operations on it.');
    }

    $stored_lock = $this->tempStore->getIfOwner(static::TEMPSTORE_LOCK_KEY);
    if ($stored_lock !== $this->lock) {
      throw new StageOwnershipException('Stage is not owned by the current user or session.');
    }
  }

  /**
   * Returns the path of the directory where changes should be staged.
   *
   * @return string
   *   The absolute path of the directory where changes should be staged.
   *
   * @throws \LogicException
   *   If this method is called before the stage has been created or claimed.
   */
  public function getStageDirectory(): string {
    if (!$this->lock) {
      throw new \LogicException(__METHOD__ . '() cannot be called because the stage has not been created or claimed.');
    }
    return $this->getStagingRoot() . DIRECTORY_SEPARATOR . $this->lock[0];
  }

  /**
   * Returns the directory where staging areas will be created.
   *
   * @return string
   *   The absolute path of the directory containing the staging areas managed
   *   by this class.
   */
  private function getStagingRoot(): string {
    // Since the staging root can depend on site settings, store it so that
    // things won't break if the settings change during this stage's life
    // cycle.
    $dir = $this->tempStore->get(self::TEMPSTORE_STAGING_ROOT_KEY);
    if (empty($dir)) {
      $site_id = $this->configFactory->get('system.site')->get('uuid');
      $dir = $this->fileSystem->getTempDirectory() . DIRECTORY_SEPARATOR . '.package_manager' . $site_id;
      $this->tempStore->set(self::TEMPSTORE_STAGING_ROOT_KEY, $dir);
    }
    return $dir;
  }

  /**
   * Checks if staged changes are being applied to the active directory.
   *
   * @return bool
   *   TRUE if the staged changes are being applied to the active directory, and
   *   it has been less than an hour since that operation began. If more than an
   *   hour has elapsed since the changes started to be applied, FALSE is
   *   returned even if the stage internally thinks that changes are still being
   *   applied.
   *
   * @see ::apply()
   */
  final public function isApplying(): bool {
    $apply_time = $this->tempStore->get(self::TEMPSTORE_APPLY_TIME_KEY);
    return isset($apply_time) && $this->time->getRequestTime() - $apply_time < 3600;
  }

}
