<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Traits;

use Drupal\Component\Utility\NestedArray;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Iterator\RecursiveDirectoryIterator;

/**
 * A utility for all things fixtures.
 *
 * @internal
 */
trait FixtureUtilityTrait {

  /**
   * Mirrors a fixture directory to the given path.
   *
   * Files not in the source fixture directory will not be deleted from
   * destination directory. After copying the files to the destination directory
   * the files and folders will be converted so that can be used in the tests.
   * The conversion includes:
   * - Renaming '_git' directories to '.git'
   * - Renaming files ending in '.info.yml.hide' to remove '.hide'.
   *
   * @param string $source_path
   *   The source path.
   * @param string $destination_path
   *   The path to which the fixture files should be mirrored.
   */
  protected static function copyFixtureFilesTo(string $source_path, string $destination_path): void {
    (new Filesystem())->mirror($source_path, $destination_path, NULL, [
      'override' => TRUE,
      'delete' => FALSE,
    ]);
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
    $iter = new \RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::SELF_FIRST,
      \RecursiveIteratorIterator::CATCH_GET_CHILD
    );
    /** @var \Symfony\Component\Finder\SplFileInfo $file */
    foreach ($iter as $file) {
      if ($file->isDir() && $file->getFilename() === '_git' && $file->getRelativePathname()) {
        rename(
          $file->getPathname(),
          $file->getPath() . DIRECTORY_SEPARATOR . '.git'
        );
      }
    }
  }

  /**
   * Adds a package.
   *
   * If $package contains an `install_path` key, it should be relative to the
   * location of `installed.json` and `installed.php`, which are in
   * `vendor/composer`. For example, if the package would be installed at
   * `vendor/kirk/enterprise`, the install path should be `../kirk/enterprise`.
   * If the package would be installed outside of vendor (for example, a Drupal
   * module in the `modules` directory), it would be `../../modules/my_module`.
   *
   * @param string $dir
   *   The root Composer-managed directory (e.g., the project root or staging
   *   area).
   * @param array $package
   *   The package info that should be added to installed.json and
   *   installed.php. Must include the `name` and `type` keys.
   * @param bool $is_dev_requirement
   *   Whether or not the package is a development requirement.
   */
  protected function addPackage(string $dir, array $package, bool $is_dev_requirement = FALSE): void {
    foreach (['name', 'type'] as $required_key) {
      $this->assertArrayHasKey($required_key, $package);
    }
    $this->setPackage($dir, $package['name'], $package, FALSE, $is_dev_requirement);
  }

  /**
   * Modifies a package's installed info.
   *
   * See ::addPackage() for information on how the `install_path` key is
   * handled, if $package has it.
   *
   * @param string $dir
   *   The root Composer-managed directory (e.g., the project root or staging
   *   area).
   * @param string $name
   *   The name of the package to modify.
   * @param array $package
   *   The package info that should be updated in installed.json and
   *   installed.php.
   */
  protected function modifyPackage(string $dir, string $name, array $package): void {
    $this->setPackage($dir, $name, $package, TRUE);
  }

  /**
   * Removes a package.
   *
   * @param string $dir
   *   The root Composer-managed directory (e.g., the project root or staging
   *   area).
   * @param string $name
   *   The name of the package to remove.
   */
  protected function removePackage(string $dir, string $name): void {
    $this->setPackage($dir, $name, NULL, TRUE);
  }

  /**
   * Changes a package's installation information in a particular directory.
   *
   * This function is internal and should not be called directly. Use
   * ::addPackage(), ::modifyPackage(), and ::removePackage() instead.
   *
   * @param string $dir
   *   The root Composer-managed directory (e.g., the project root or staging
   *   area).
   * @param string $name
   *   The name of the package to add, update, or remove.
   * @param array|null $package
   *   The package information to be set in installed.json and installed.php, or
   *   NULL to remove it. Will be merged into the existing information if the
   *   package is already installed.
   * @param bool $should_exist
   *   Whether or not the package is expected to already be installed.
   * @param bool|null $is_dev_requirement
   *   Whether or not the package is a developer requirement.
   */
  private function setPackage(string $dir, string $name, ?array $package, bool $should_exist, ?bool $is_dev_requirement = NULL): void {
    $this->assertNotTrue($should_exist && isset($is_dev_requirement), 'Changing an existing project to a dev requirement is not supported');
    $dir .= '/vendor/composer';

    $file = $dir . '/installed.json';
    $this->assertFileIsWritable($file);

    $data = file_get_contents($file);
    $data = json_decode($data, TRUE, 512, JSON_THROW_ON_ERROR);

    // If the package is already installed, find its numerical index.
    $position = NULL;
    for ($i = 0; $i < count($data['packages']); $i++) {
      if ($data['packages'][$i]['name'] === $name) {
        $position = $i;
        break;
      }
    }
    // Ensure that we actually expect to find the package already installed (or
    // not).
    $message = $should_exist
      ? "Expected package '$name' to be installed, but it wasn't."
      : "Expected package '$name' to not be installed, but it was.";
    $this->assertSame($should_exist, isset($position), $message);

    if ($package) {
      $package = ['name' => $name] + $package;
      $install_json_package = array_diff_key($package, array_flip(['install_path']));
    }

    if (isset($position)) {
      // If we're going to be updating the package data, merge the incoming data
      // into what we already have.
      if ($package) {
        $install_json_package = NestedArray::mergeDeep($data['packages'][$position], $install_json_package);
      }

      // Remove the existing package; the array will be re-keyed by
      // array_splice().
      array_splice($data['packages'], $position, 1);
      $is_existing_dev_package = in_array($name, $data['dev-package-names'], TRUE);
      $data['dev-package-names'] = array_diff($data['dev-package-names'], [$name]);
      $data['dev-package-names'] = array_values($data['dev-package-names']);
    }
    // Add the package back to the list, if we have data for it.
    if (isset($package)) {
      $data['packages'][] = $install_json_package;

      if ($is_dev_requirement || !empty($is_existing_dev_package)) {
        $data['dev-package-names'][] = $name;
      }
    }
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $file = $dir . '/installed.php';
    $this->assertFileIsWritable($file);

    $data = require $file;

    // Ensure that we actually expect to find the package already installed (or
    // not).
    if ($should_exist) {
      $this->assertArrayHasKey($name, $data['versions']);
    }
    else {
      $this->assertArrayNotHasKey($name, $data['versions']);
    }
    if ($package) {
      // If an install path was provided, ensure it's relative.
      if (array_key_exists('install_path', $package)) {
        $this->assertStringStartsWith('../', $package['install_path']);
      }
      $install_php_package = $should_exist ?
        NestedArray::mergeDeep($data['versions'][$name], $package) :
        $package;

      // The installation paths in $data will have been interpreted by the PHP
      // runtime, so make them all relative again by stripping $dir out.
      array_walk($data['versions'], function (array &$install_php_package) use ($dir): void {
        if (array_key_exists('install_path', $install_php_package)) {
          $install_php_package['install_path'] = str_replace("$dir/", '', $install_php_package['install_path']);
        }
      });
      $data['versions'][$name] = $install_php_package;
    }
    else {
      unset($data['versions'][$name]);
    }

    $data = var_export($data, TRUE);
    $data = str_replace("'install_path' => '../", "'install_path' => __DIR__ . '/../", $data);
    file_put_contents($file, "<?php\nreturn $data;");
  }

}
