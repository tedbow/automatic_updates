<?php

namespace Drupal\package_manager;

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
   */
  public function __construct(PathLocator $path_locator, BeginnerInterface $beginner, StagerInterface $stager, CommitterInterface $committer, CleanerInterface $cleaner, EventDispatcherInterface $event_dispatcher) {
    $this->pathLocator = $path_locator;
    $this->beginner = $beginner;
    $this->stager = $stager;
    $this->committer = $committer;
    $this->cleaner = $cleaner;
    $this->eventDispatcher = $event_dispatcher;
  }

  /**
   * Copies the active code base into the staging area.
   */
  public function create(): void {
    $active_dir = $this->pathLocator->getActiveDirectory();
    $stage_dir = $this->pathLocator->getStageDirectory();

    $event = new PreCreateEvent();
    $this->dispatch($event);

    $this->beginner->begin($active_dir, $stage_dir, $event->getExcludedPaths());
    $this->dispatch(new PostCreateEvent());
  }

  /**
   * Requires packages in the staging area.
   *
   * @param string[] $constraints
   *   The packages to require, in the form 'vendor/name:version'.
   */
  public function require(array $constraints): void {
    $command = array_merge(['require'], $constraints);
    $command[] = '--update-with-all-dependencies';

    $this->dispatch(new PreRequireEvent());
    $this->stager->stage($command, $this->pathLocator->getStageDirectory());
    $this->dispatch(new PostRequireEvent());
  }

  /**
   * Applies staged changes to the active directory.
   */
  public function apply(): void {
    $active_dir = $this->pathLocator->getActiveDirectory();
    $stage_dir = $this->pathLocator->getStageDirectory();

    $event = new PreApplyEvent();
    $this->dispatch($event);

    $this->committer->commit($stage_dir, $active_dir, $event->getExcludedPaths());
    $this->dispatch(new PostApplyEvent());
  }

  /**
   * Deletes the staging area.
   */
  public function destroy(): void {
    $this->dispatch(new PreDestroyEvent());
    $stage_dir = $this->pathLocator->getStageDirectory();
    if (is_dir($stage_dir)) {
      $this->cleaner->clean($stage_dir);
    }
    $this->dispatch(new PostDestroyEvent());
  }

  /**
   * Dispatches an event and handles any errors that it collects.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   */
  protected function dispatch(StageEvent $event): void {
    $this->eventDispatcher->dispatch($event);

    $errors = $event->getResults(SystemManager::REQUIREMENT_ERROR);
    if ($errors) {
      throw new StageException($errors);
    }
  }

}
