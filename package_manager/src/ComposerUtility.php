<?php

namespace Drupal\package_manager;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Package\PackageInterface;
use Composer\Semver\Comparator;
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
   * Returns the underlying Composer instance.
   *
   * @return \Composer\Composer
   *   The Composer instance.
   */
  public function getComposer(): Composer {
    return $this->composer;
  }

  /**
   * Creates an instance of this class using the files in a given directory.
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
    // Disable the automatic generation of .htaccess files in the Composer home
    // directory, since we are temporarily overriding that directory.
    // @see \Composer\Factory::createConfig()
    // @see https://getcomposer.org/doc/06-config.md#htaccess-protect
    $htaccess = getenv('COMPOSER_HTACCESS_PROTECT');

    $factory = new Factory();
    putenv("COMPOSER_HOME=$dir");
    putenv("COMPOSER_HTACCESS_PROTECT=false");
    // Initialize the Composer API with plugins disabled and only the root
    // package loaded (i.e., nothing from the global Composer project will be
    // considered or loaded). This allows us to inspect the project directory
    // using Composer's API in a "hands-off" manner.
    $composer = $factory->createComposer($io, $configuration, TRUE, $dir, FALSE);
    putenv("COMPOSER_HOME=$home");
    putenv("COMPOSER_HTACCESS_PROTECT=$htaccess");

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
   * Returns the names of the installed core packages.
   *
   * All packages listed in ../core_packages.json are considered core packages.
   *
   * @return string[]
   *   The names of the required core packages.
   *
   * @todo Make this return a keyed array of packages, not just names in
   *   https://www.drupal.org/i/3258059.
   */
  public function getCorePackageNames(): array {
    $core_packages = array_intersect(
      array_keys($this->getInstalledPackages()),
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
   * Returns information on all installed packages.
   *
   * @return \Composer\Package\PackageInterface[]
   *   All installed packages, keyed by name.
   */
  public function getInstalledPackages(): array {
    $installed_packages = $this->getComposer()
      ->getRepositoryManager()
      ->getLocalRepository()
      ->getPackages();

    $packages = [];
    foreach ($installed_packages as $package) {
      $key = $package->getName();
      $packages[$key] = $package;
    }
    return $packages;
  }

  /**
   * Returns the packages that are in the current project, but not in another.
   *
   * @param self $other
   *   A Composer utility wrapper around a different directory.
   *
   * @return \Composer\Package\PackageInterface[]
   *   The packages which are installed in the current project, but not in the
   *   other one, keyed by name.
   */
  public function getPackagesNotIn(self $other): array {
    return array_diff_key($this->getInstalledPackages(), $other->getInstalledPackages());
  }

  /**
   * Returns the packages which have a different version in another project.
   *
   * This compares the current project with another one, and returns packages
   * which are present in both, but in different versions.
   *
   * @param self $other
   *   A Composer utility wrapper around a different directory.
   *
   * @return \Composer\Package\PackageInterface[]
   *   The packages which are present in both the current project and the other
   *   one, but in different versions, keyed by name.
   */
  public function getPackagesWithDifferentVersionsIn(self $other): array {
    $theirs = $other->getInstalledPackages();

    // Only compare packages that are both here and there.
    $packages = array_intersect_key($this->getInstalledPackages(), $theirs);

    $filter = function (PackageInterface $package, string $name) use ($theirs): bool {
      return Comparator::notEqualTo($package->getVersion(), $theirs[$name]->getVersion());
    };
    return array_filter($packages, $filter, ARRAY_FILTER_USE_BOTH);
  }

}
