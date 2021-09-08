<?php

namespace Drupal\Tests\automatic_updates\Traits;

use Drupal\Component\FileSystem\FileSystem;
use Drupal\Component\Utility\NestedArray;
use PHPUnit\Framework\Assert;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;

/**
 * Provides methods for interacting with installed Composer packages.
 */
trait LocalPackagesTrait {

  use JsonTrait;

  /**
   * The paths of temporary copies of packages.
   *
   * @see ::copyPackage()
   * @see ::deleteCopiedPackages()
   *
   * @var string[]
   */
  private $copiedPackages = [];

  /**
   * Returns the path of an installed package, relative to composer.json.
   *
   * @param array $package
   *   The package information, as read from the lock file.
   *
   * @return string
   *   The path of the installed package, relative to composer.json.
   */
  protected function getPackagePath(array $package): string {
    return 'vendor' . DIRECTORY_SEPARATOR . $package['name'];
  }

  /**
   * Deletes all copied packages.
   *
   * @see ::copyPackage()
   */
  protected function deleteCopiedPackages(): void {
    (new SymfonyFilesystem())->remove($this->copiedPackages);
  }

  /**
   * Copies a package's entire directory to another location.
   *
   * The copies' paths will be stored so that they can be easily deleted by
   * ::deleteCopiedPackages().
   *
   * @param string $source_dir
   *   The path of the package directory to copy.
   * @param string|null $destination_dir
   *   (optional) The directory to which the package should be copied. Will be
   *   suffixed with a random string to ensure uniqueness. If not given, the
   *   system temporary directory will be used.
   *
   * @return string
   *   The path of the temporary copy.
   *
   * @see ::deleteCopiedPackages()
   */
  protected function copyPackage(string $source_dir, string $destination_dir = NULL): string {
    Assert::assertDirectoryExists($source_dir);

    if (empty($destination_dir)) {
      $destination_dir = FileSystem::getOsTemporaryDirectory();
      Assert::assertNotEmpty($destination_dir);
      $destination_dir .= DIRECTORY_SEPARATOR;
    }
    $destination_dir = uniqid($destination_dir);
    Assert::assertDirectoryDoesNotExist($destination_dir);

    (new SymfonyFilesystem())->mirror($source_dir, $destination_dir);
    array_push($this->copiedPackages, $destination_dir);

    return $destination_dir;
  }

  /**
   * Generates local path repositories for a set of installed packages.
   *
   * @param string $dir
   *   The directory which contains composer.lock.
   *
   * @return mixed[][]
   *   The local path repositories' configuration, for inclusion in a
   *   composer.json file.
   */
  protected function getLocalPackageRepositories(string $dir): array {
    $repositories = [];

    foreach ($this->getPackagesFromLockFile($dir) as $package) {
      // Ensure the package directory is writable, since we'll need to make a
      // few changes to it.
      $path = $dir . DIRECTORY_SEPARATOR . $this->getPackagePath($package);
      Assert::assertIsWritable($path);
      $composer = $path . DIRECTORY_SEPARATOR . 'composer.json';

      // Overwrite the composer.json with the fully resolved package information
      // from the lock file.
      // @todo Back up composer.json before overwriting it?
      $this->writeJson($composer, $package);

      $name = $package['name'];
      $repositories[$name] = [
        'type' => 'path',
        'url' => $path,
        'options' => [
          'symlink' => FALSE,
        ],
      ];
    }
    return $repositories;
  }

  /**
   * Alters a package's composer.json file.
   *
   * @param string $package_dir
   *   The package directory.
   * @param array $changes
   *   The changes to merge into composer.json.
   */
  protected function alterPackage(string $package_dir, array $changes): void {
    $composer = $package_dir . DIRECTORY_SEPARATOR . 'composer.json';
    $data = $this->readJson($composer);
    $data = NestedArray::mergeDeep($data, $changes);
    $this->writeJson($composer, $data);
  }

  /**
   * Reads all package information from a composer.lock file.
   *
   * @param string $dir
   *   The directory which contains the lock file.
   *
   * @return mixed[][]
   *   All package information (including dev packages) from the lock file.
   */
  private function getPackagesFromLockFile(string $dir): array {
    $lock = $this->readJson($dir . DIRECTORY_SEPARATOR . 'composer.lock');
    return array_merge($lock['packages'], $lock['packages-dev']);
  }

}
