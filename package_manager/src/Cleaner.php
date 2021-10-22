<?php

namespace Drupal\package_manager;

use PhpTuf\ComposerStager\Domain\Cleaner as StagerCleaner;
use PhpTuf\ComposerStager\Domain\CleanerInterface;
use PhpTuf\ComposerStager\Domain\Output\ProcessOutputCallbackInterface;
use PhpTuf\ComposerStager\Infrastructure\Filesystem\FilesystemInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Defines a cleaner service that makes the staged site directory writable.
 */
class Cleaner implements CleanerInterface {

  /**
   * The decorated cleaner service.
   *
   * @var \PhpTuf\ComposerStager\Domain\CleanerInterface
   */
  protected $decorated;

  /**
   * The current site path, without leading or trailing slashes.
   *
   * @var string
   */
  protected $sitePath;

  /**
   * The path locator service.
   *
   * @var \Drupal\package_manager\PathLocator
   */
  protected $pathLocator;

  /**
   * Constructs a Cleaner object.
   *
   * @param \PhpTuf\ComposerStager\Infrastructure\Filesystem\FilesystemInterface $file_system
   *   The file system service from Composer Stager.
   * @param string $site_path
   *   The current site path (e.g., 'sites/default'), without leading or
   *   trailing slashes.
   * @param \Drupal\package_manager\PathLocator $path_locator
   *   The path locator service.
   */
  public function __construct(FilesystemInterface $file_system, string $site_path, PathLocator $path_locator) {
    $this->decorated = new StagerCleaner($file_system);
    $this->sitePath = $site_path;
    $this->pathLocator = $path_locator;
  }

  /**
   * {@inheritdoc}
   */
  public function clean(string $stagingDir, ?ProcessOutputCallbackInterface $callback = NULL, ?int $timeout = 120): void {
    // Ensure that the staged site directory is writable so we can delete it.
    $site_dir = implode(DIRECTORY_SEPARATOR, [
      $stagingDir,
      $this->pathLocator->getWebRoot() ?: '.',
      $this->sitePath,
    ]);

    if ($this->directoryExists($site_dir)) {
      (new Filesystem())->chmod($site_dir, 0777);
    }
    $this->decorated->clean($stagingDir, $callback, $timeout);
  }

  /**
   * {@inheritdoc}
   */
  public function directoryExists(string $stagingDir): bool {
    return $this->decorated->directoryExists($stagingDir);
  }

}
