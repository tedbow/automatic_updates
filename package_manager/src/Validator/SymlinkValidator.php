<?php

namespace Drupal\package_manager\Validator;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;
use Drupal\package_manager\PathLocator;
use Symfony\Component\Finder\Finder;

/**
 * Flags errors if the project root or staging area contain symbolic links.
 *
 * @todo Remove this when Composer Stager's PHP file copier handles symlinks
 *   without issues.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
class SymlinkValidator implements PreOperationStageValidatorInterface {

  use StringTranslationTrait;

  /**
   * The path locator service.
   *
   * @var \Drupal\package_manager\PathLocator
   */
  protected $pathLocator;

  /**
   * Constructs a SymlinkValidator object.
   *
   * @param \Drupal\package_manager\PathLocator $path_locator
   *   The path locator service.
   */
  public function __construct(PathLocator $path_locator) {
    $this->pathLocator = $path_locator;
  }

  /**
   * {@inheritdoc}
   */
  public function validateStagePreOperation(PreOperationStageEvent $event): void {
    $dir = $this->pathLocator->getProjectRoot();

    if ($this->hasLinks($dir)) {
      $event->addError([
        $this->t('Symbolic links were found in the active directory, which are not supported at this time.'),
      ]);
    }
  }

  /**
   * Checks if the staging area has any symbolic links.
   *
   * @param \Drupal\package_manager\Event\PreApplyEvent $event
   *   The event object.
   */
  public function preApply(PreApplyEvent $event): void {
    $dir = $event->getStage()->getStageDirectory();

    if ($this->hasLinks($dir)) {
      $event->addError([
        $this->t('Symbolic links were found in the staging area, which are not supported at this time.'),
      ]);
    }
  }

  /**
   * Recursively checks if a directory has any symbolic links.
   *
   * @param string $dir
   *   The path of the directory to check.
   *
   * @return bool
   *   TRUE if the directory contains any symbolic links, FALSE otherwise.
   */
  protected function hasLinks(string $dir): bool {
    // Finder::filter() explicitly requires a closure, so create one from
    // ::isLink() so that we can still override it for testing purposes.
    $is_link = \Closure::fromCallable([$this, 'isLink']);

    // Finder::hasResults() is more efficient than count() because it will
    // return early if there is a match.
    return Finder::create()
      ->in($dir)
      ->filter($is_link)
      ->ignoreUnreadableDirs()
      ->hasResults();
  }

  /**
   * Checks if a file or directory is a symbolic link.
   *
   * @param \SplFileInfo $file
   *   A value object for the file or directory.
   *
   * @return bool
   *   TRUE if the file or directory is a symbolic link, FALSE otherwise.
   */
  protected function isLink(\SplFileInfo $file): bool {
    return $file->isLink();
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      PreCreateEvent::class => 'validateStagePreOperation',
      PreApplyEvent::class => [
        ['validateStagePreOperation'],
        ['preApply'],
      ],
    ];
  }

}
