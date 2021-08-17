<?php

namespace Drupal\Tests\automatic_updates\Traits;

use PHPUnit\Framework\Assert;

/**
 * Provides methods for interacting with installed Composer packages.
 */
trait LocalPackagesTrait {

  use JsonTrait;

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
