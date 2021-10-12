<?php

namespace Drupal\package_manager;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\NullIO;
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
   * Returns the names of the core packages required in composer.json.
   *
   * All packages listed in ../core_packages.json are considered core packages.
   *
   * @return string[]
   *   The names of the required core packages.
   *
   * @throws \LogicException
   *   If neither drupal/core or drupal/core-recommended are required.
   *
   * @todo Make this return a keyed array of packages, not just names.
   */
  public function getCorePackageNames(): array {
    $requirements = array_keys($this->composer->getPackage()->getRequires());

    // Ensure that either drupal/core or drupal/core-recommended are required.
    // If neither is, then core cannot be updated, which we consider an error
    // condition.
    // @todo Move this check to an update validator as part of
    //   https://www.drupal.org/project/automatic_updates/issues/3241105
    $core_requirements = array_intersect(['drupal/core', 'drupal/core-recommended'], $requirements);
    if (empty($core_requirements)) {
      $file = $this->composer->getConfig()->getConfigSource()->getName();
      throw new \LogicException("Drupal core does not appear to be required in $file.");
    }

    return array_intersect(static::getCorePackageList(), $requirements);
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
    $locked_packages = $this->composer->getLocker()
      ->getLockedRepository(TRUE)
      ->getPackages();

    $drupal_package_types = [
      'drupal-module',
      'drupal-theme',
      'drupal-custom-module',
      'drupal-custom-theme',
    ];
    $drupal_packages = [];
    foreach ($locked_packages as $package) {
      if (in_array($package->getType(), $drupal_package_types, TRUE)) {
        $key = $package->getName();
        $drupal_packages[$key] = $package;
      }
    }
    return $drupal_packages;
  }

}
