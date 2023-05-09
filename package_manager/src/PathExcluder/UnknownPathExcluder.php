<?php

declare(strict_types = 1);

namespace Drupal\package_manager\PathExcluder;

use Drupal\Component\Serialization\Json;
use Drupal\package_manager\ComposerInspector;
use Drupal\package_manager\Event\CollectPathsToExcludeEvent;
use Drupal\package_manager\PathLocator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Excludes unknown paths from stage operations.
 *
 * Any paths in the root directory of the project that are NOT one of the
 * following are considered unknown paths:
 * 1. The vendor directory
 * 2. The web root
 * 3. composer.json
 * 4. composer.lock
 * 5. Scaffold files as determined by the drupal/core-composer-scaffold plugin
 *
 * If web root and project root are the same, nothing is excluded.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
final class UnknownPathExcluder implements EventSubscriberInterface {

  /**
   * Constructs a UnknownPathExcluder object.
   *
   * @param \Drupal\package_manager\ComposerInspector $composerInspector
   *   The Composer inspector service.
   * @param \Drupal\package_manager\PathLocator $pathLocator
   *   The path locator service.
   */
  public function __construct(
    private readonly ComposerInspector $composerInspector,
    private readonly PathLocator $pathLocator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      CollectPathsToExcludeEvent::class => 'excludeUnknownPaths',
    ];
  }

  /**
   * Excludes unknown paths from stage operations.
   *
   * @param \Drupal\package_manager\Event\CollectPathsToExcludeEvent $event
   *   The event object.
   *
   * @throws \Exception
   *   See \Drupal\package_manager\ComposerInspector::validate().
   */
  public function excludeUnknownPaths(CollectPathsToExcludeEvent $event): void {
    $project_root = $this->pathLocator->getProjectRoot();
    $web_root = $project_root . DIRECTORY_SEPARATOR . $this->pathLocator->getWebRoot();
    if (realpath($web_root) === $project_root) {
      return;
    }

    // To determine the scaffold files to exclude, the installed packages must
    // be known, and that requires Composer commands to be able to run. This
    // intentionally does not catch exceptions: failed Composer validation in
    // the project root implies that this excluder cannot function correctly.
    // Note: the call to ComposerInspector::getInstalledPackagesList() would
    // also have triggered this, but explicitness is preferred here.
    // @see \Drupal\package_manager\StatusCheckTrait::runStatusCheck()
    $this->composerInspector->validate($project_root);

    $vendor_dir = $this->pathLocator->getVendorDirectory();
    $scaffold_files_paths = $this->getScaffoldFiles();
    // Search for all files (including hidden ones) in project root.
    $paths_in_project_root = glob("$project_root/{,.}*", GLOB_BRACE);
    $paths = [];
    $known_paths = array_merge([$vendor_dir, $web_root, "$project_root/composer.json", "$project_root/composer.lock"], $scaffold_files_paths);
    foreach ($paths_in_project_root as $path_in_project_root) {
      if (!in_array($path_in_project_root, $known_paths, TRUE)) {
        $paths[] = $path_in_project_root;
      }
    }
    $event->addPathsRelativeToProjectRoot($paths);
  }

  /**
   * Gets the path of scaffold files, for example 'index.php' and 'robots.txt'.
   *
   * @return array
   *   The array of scaffold file paths.
   *
   * @todo Intelligently load scaffold files in https://drupal.org/i/3343802.
   */
  private function getScaffoldFiles(): array {
    $project_root = $this->pathLocator->getProjectRoot();
    $packages = $this->composerInspector->getInstalledPackagesList($project_root);
    $extra = Json::decode($this->composerInspector->getConfig('extra', $packages['drupal/core']->path . '/composer.json'));
    if (isset($extra['drupal-scaffold']['file-mapping'])) {
      return array_keys($extra['drupal-scaffold']['file-mapping']);
    }
    return [];
  }

}
