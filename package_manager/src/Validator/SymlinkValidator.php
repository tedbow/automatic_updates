<?php

namespace Drupal\package_manager\Validator;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;
use Drupal\package_manager\Event\StatusCheckEvent;
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
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The path locator service.
   *
   * @var \Drupal\package_manager\PathLocator
   */
  protected $pathLocator;

  /**
   * Constructs a SymlinkValidator object.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\package_manager\PathLocator $path_locator
   *   The path locator service.
   */
  public function __construct(ModuleHandlerInterface $module_handler, PathLocator $path_locator) {
    $this->moduleHandler = $module_handler;
    $this->pathLocator = $path_locator;
  }

  /**
   * {@inheritdoc}
   */
  public function validateStagePreOperation(PreOperationStageEvent $event): void {
    $dir = $this->pathLocator->getProjectRoot();

    if ($this->hasLinks($dir)) {
      $this->addError('Symbolic links were found in the active directory, which are not supported at this time.', $event);
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
      $this->addError('Symbolic links were found in the staging area, which are not supported at this time.', $event);
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
      StatusCheckEvent::class => 'validateStagePreOperation',
      PreApplyEvent::class => [
        ['validateStagePreOperation'],
        ['preApply'],
      ],
    ];
  }

  /**
   * Adds a validation error to a given event.
   *
   * @param string $message
   *   The error message. If the Help module is enabled, a link to Package
   *   Manager's help page will be appended.
   * @param \Drupal\package_manager\Event\PreApplyEvent|\Drupal\package_manager\Event\PreOperationStageEvent $event
   *   The event to add the error to.
   *
   * @see package_manager_help()
   */
  protected function addError(string $message, $event): void {
    if ($this->moduleHandler->moduleExists('help')) {
      $url = Url::fromRoute('help.page', ['name' => 'package_manager'])
        ->setOption('fragment', 'package-manager-faq-symlinks-found')
        ->toString();

      $message = $this->t('@message See <a href=":package-manager-help">the help page</a> for information on how to resolve the problem.', [
        '@message' => $message,
        ':package-manager-help' => $url,
      ]);
    }

    $event->addError([$message]);
  }

}
