<?php

declare(strict_types = 1);

namespace Drupal\package_manager\Validator;

use Drupal\package_manager\Event\PreOperationStageEvent;
use Drupal\package_manager\PathLocator;
use PhpTuf\ComposerStager\Domain\Aggregate\PreconditionsTree\NoUnsupportedLinksExistInterface;
use PhpTuf\ComposerStager\Domain\Exception\PreconditionException;
use PhpTuf\ComposerStager\Infrastructure\Factory\Path\PathFactoryInterface;
use PhpTuf\ComposerStager\Infrastructure\Value\PathList\PathList;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Flags errors if unsupported symbolic links are detected.
 *
 * @see https://github.com/php-tuf/composer-stager/tree/develop/src/Domain/Service/Precondition#symlinks
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
class SymlinkValidator implements EventSubscriberInterface {

  use BaseRequirementValidatorTrait;

  /**
   * Constructs a SymlinkValidator object.
   *
   * @param \Drupal\package_manager\PathLocator $pathLocator
   *   The path locator service.
   * @param \PhpTuf\ComposerStager\Domain\Aggregate\PreconditionsTree\NoUnsupportedLinksExistInterface $precondition
   *   The Composer Stager precondition that this validator wraps.
   * @param \PhpTuf\ComposerStager\Infrastructure\Factory\Path\PathFactoryInterface $pathFactory
   *   The path factory service.
   */
  public function __construct(
    protected PathLocator $pathLocator,
    protected NoUnsupportedLinksExistInterface $precondition,
    protected PathFactoryInterface $pathFactory,
  ) {}

  /**
   * Flags errors if the project root or stage directory contain symbolic links.
   */
  public function validate(PreOperationStageEvent $event): void {
    $active_dir = $this->pathFactory->create($this->pathLocator->getProjectRoot());

    // The precondition requires us to pass both an active and stage directory,
    // so if the stage hasn't been created or claimed yet, use the directory
    // that contains this file, which contains only a few files and no symlinks,
    // as the stage directory. The precondition itself doesn't care if the
    // directory actually exists or not.
    $stage_dir = __DIR__;
    if ($event->stage->stageDirectoryExists()) {
      $stage_dir = $event->stage->getStageDirectory();
    }
    $stage_dir = $this->pathFactory->create($stage_dir);

    $excluded_paths = $event->getExcludedPaths();
    // Return early if no excluded paths were collected because this validator
    // is dependent on knowing which paths to exclude when searching for
    // symlinks.
    // @see \Drupal\package_manager\StatusCheckTrait::runStatusCheck()
    if ($excluded_paths === NULL) {
      return;
    }

    try {
      $this->precondition->assertIsFulfilled($active_dir, $stage_dir, new PathList($excluded_paths));
    }
    catch (PreconditionException $e) {
      $event->addErrorFromThrowable($e);
    }
  }

}
