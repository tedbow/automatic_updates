<?php

namespace Drupal\Tests\package_manager\Kernel;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Iterator\RecursiveDirectoryIterator;

/**
 * A utility for all things fixtures.
 */
class FixtureUtility {

  /**
   * If a fixture path has been set, mirrors it to the given path.
   *
   * @param string $source_path
   *   The source path.
   * @param string $destination_path
   *   The path to which the fixture files should be mirrored.
   */
  public static function copyFixtureFilesTo(string $source_path, string $destination_path): void {
    (new Filesystem())->mirror($source_path, $destination_path, NULL, [
      'override' => TRUE,
      'delete' => TRUE,
    ]);
    static::cleanUpFixtureFiles($destination_path);
  }

  /**
   * Clean up fixture files.
   *
   * @param string $destination_path
   *   The destination path.
   */
  private static function cleanUpFixtureFiles(string $destination_path) {
    static::renameInfoYmlFiles($destination_path);
    static::renameGitDirectories($destination_path);
  }

  /**
   * Renames all files that end with .info.yml.hide.
   *
   * @param string $dir
   *   The directory to be iterated through.
   */
  protected static function renameInfoYmlFiles(string $dir) {
    // Construct the iterator.
    $it = new RecursiveDirectoryIterator($dir, \RecursiveIteratorIterator::SELF_FIRST);

    // Loop through files and rename them.
    foreach (new \RecursiveIteratorIterator($it) as $file) {
      if ($file->getExtension() == 'hide') {
        rename($file->getPathname(), $dir . DIRECTORY_SEPARATOR .
          $file->getRelativePath() . DIRECTORY_SEPARATOR . str_replace(".hide", "", $file->getFilename()));
      }
    }
  }

  /**
   * Renames _git directories to .git.
   *
   * @param string $dir
   *   The directory to be iterated through.
   */
  private static function renameGitDirectories(string $dir) {
    // Construct the iterator.
    $it = new \DirectoryIterator($dir);

    // Loop through files and rename them.
    /** @var \Symfony\Component\Finder\SplFileInfo $file */
    foreach ($it as $file) {
      if ($file->isDir() && $file->getFilename() === '_git') {
        rename(
          $file->getPathname(),
          $file->getPath() . DIRECTORY_SEPARATOR . '.git'
        );
      }
    }
  }

}
