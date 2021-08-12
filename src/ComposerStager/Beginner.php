<?php

namespace Drupal\automatic_updates\ComposerStager;

use PhpTuf\ComposerStager\Domain\BeginnerInterface;
use PhpTuf\ComposerStager\Domain\Output\ProcessOutputCallbackInterface;
use PhpTuf\ComposerStager\Exception\DirectoryAlreadyExistsException;
use PhpTuf\ComposerStager\Exception\DirectoryNotFoundException;
use PhpTuf\ComposerStager\Infrastructure\Filesystem\FilesystemInterface;
use PhpTuf\ComposerStager\Infrastructure\Process\FileCopier\FileCopierInterface;

/**
 * An implementation of Composer Stager's Beginner which supports exclusions.
 *
 * @todo Remove this class when composer_stager implements this functionality.
 */
final class Beginner implements BeginnerInterface {

  /**
   * The file copier service.
   *
   * @var \PhpTuf\ComposerStager\Infrastructure\Process\FileCopier\FileCopierInterface
   */
  private $fileCopier;

  /**
   * The file system service.
   *
   * @var \PhpTuf\ComposerStager\Infrastructure\Filesystem\FilesystemInterface
   */
  private $filesystem;

  /**
   * Constructs a Beginner object.
   *
   * @param \PhpTuf\ComposerStager\Infrastructure\Process\FileCopier\FileCopierInterface $fileCopier
   *   The file copier service.
   * @param \PhpTuf\ComposerStager\Infrastructure\Filesystem\FilesystemInterface $filesystem
   *   The file system service.
   */
  public function __construct(FileCopierInterface $fileCopier, FilesystemInterface $filesystem) {
    $this->fileCopier = $fileCopier;
    $this->filesystem = $filesystem;
  }

  /**
   * {@inheritdoc}
   */
  public function begin(string $activeDir, string $stagingDir, ?ProcessOutputCallbackInterface $callback = NULL, ?int $timeout = 120, array $exclusions = []): void {
    if (!$this->filesystem->exists($activeDir)) {
      throw new DirectoryNotFoundException($activeDir, 'The active directory does not exist at "%s"');
    }

    if ($this->filesystem->exists($stagingDir)) {
      throw new DirectoryAlreadyExistsException($stagingDir, 'The staging directory already exists at "%s"');
    }

    $this->fileCopier->copy(
          $activeDir,
          $stagingDir,
          $exclusions,
          $callback,
          $timeout
      );
  }

}
