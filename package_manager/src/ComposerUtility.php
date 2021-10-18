<?php

namespace Drupal\package_manager;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Package\PackageInterface;
use Drupal\Component\Serialization\Json;

/**
 * Defines a utility object to get information from Composer's API.
 */
class ComposerUtility {

  /**
   * The Composer instance.
   *
   * @var \Composer\Composer
   */
  protected $composer;

  /**
   * The statically cached names of the Drupal core packages.
   *
   * @var string[]
   */
  private static $corePackages;

  /**
   * Constructs a new ComposerUtility object.
   *
   * @param \Composer\Composer $composer
   *   The Composer instance.
   */
  public function __construct(Composer $composer) {
    $this->composer = $composer;
  }

  /**
   * Creates a utility object using the files in a given directory.
   *
   * @param string $dir
   *   The directory that contains composer.json and composer.lock.
   *
   * @return \Drupal\package_manager\ComposerUtility
   *   The utility object.
   */
  public static function createForDirectory(string $dir): self {
    $io = new NullIO();
    $configuration = $dir . DIRECTORY_SEPARATOR . 'composer.json';

    // The Composer factory requires that either the HOME or COMPOSER_HOME
    // environment variables be set, so momentarily set the COMPOSER_HOME
    // variable to the directory we're trying to create a Composer instance for.
    // We have to do this because the Composer factory doesn't give us a way to
    // pass the home directory in.
    // @see \Composer\Factory::getHomeDir()
    $home = getenv('COMPOSER_HOME');
    putenv("COMPOSER_HOME=$dir");
    $composer = Factory::create($io, $configuration);
    putenv("COMPOSER_HOME=$home");

    return new static($composer);
  }

  /**
   * Returns the canonical names of the supported core packages.
   *
   * @return string[]
   *   The canonical list of supported core package names, as listed in
   *   ../core_packages.json.
   */
  protected static function getCorePackageList(): array {
    if (self::$corePackages === NULL) {
      $file = __DIR__ . '/../core_packages.json';
      assert(file_exists($file), "$file does not exist.");

      $core_packages = file_get_contents($file);
      $core_packages = Json::decode($core_packages);

      assert(is_array($core_packages), "$file did not contain a list of core packages.");
      self::$corePackages = $core_packages;
    }
    return self::$corePackages;
  }

  /**
   * Returns the names of the core packages in the lock file.
   *
   * All packages listed in ../core_packages.json are considered core packages.
   *
   * @return string[]
   *   The names of the required core packages.
   *
   * @todo Make this return a keyed array of packages, not just names.
   */
  public function getCorePackageNames(): array {
    $core_packages = array_intersect(
      array_keys($this->getLockedPackages()),
      static::getCorePackageList()
    );

    // If drupal/core-recommended is present, it supersedes drupal/core, since
    // drupal/core will always be one of its direct dependencies.
    if (in_array('drupal/core-recommended', $core_packages, TRUE)) {
      $core_packages = array_diff($core_packages, ['drupal/core']);
    }
    return array_values($core_packages);
  }

  /**
   * Returns all Drupal extension packages in the lock file.
   *
   * The following package types are considered Drupal extension packages:
   * drupal-module, drupal-theme, drupal-custom-module, and drupal-custom-theme.
   *
   * @return \Composer\Package\PackageInterface[]
   *   All Drupal extension packages in the lock file, keyed by name.
   */
  public function getDrupalExtensionPackages(): array {
    $filter = function (PackageInterface $package): bool {
      $drupal_package_types = [
        'drupal-module',
        'drupal-theme',
        'drupal-custom-module',
        'drupal-custom-theme',
      ];
      return in_array($package->getType(), $drupal_package_types, TRUE);
    };
    return array_filter($this->getLockedPackages(), $filter);
  }

  /**
   * Returns all packages in the lock file.
   *
   * @return \Composer\Package\PackageInterface[]
   *   All packages in the lock file, keyed by name.
   */
  protected function getLockedPackages(): array {
    $locked_packages = $this->composer->getLocker()
      ->getLockedRepository(TRUE)
      ->getPackages();

    $packages = [];
    foreach ($locked_packages as $package) {
      $key = $package->getName();
      $packages[$key] = $package;
    }
    return $packages;
  }

}
