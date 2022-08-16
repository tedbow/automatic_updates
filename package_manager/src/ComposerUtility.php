<?php

namespace Drupal\package_manager;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Package\PackageInterface;
use Composer\Semver\Comparator;
use Drupal\Component\Serialization\Yaml;

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
      $file = __DIR__ . '/../core_packages.yml';
      assert(file_exists($file), "$file does not exist.");

      $core_packages = file_get_contents($file);
      $core_packages = Yaml::decode($core_packages);

      assert(is_array($core_packages), "$file did not contain a list of core packages.");
      self::$corePackages = $core_packages;
    }
    return self::$corePackages;
  }

  /**
   * Returns the installed core packages.
   *
   * All packages listed in ../core_packages.json are considered core packages.
   *
   * @return \Composer\Package\PackageInterface[]
   *   The installed core packages.
   */
  public function getCorePackages(): array {
    $core_packages = array_intersect_key(
      $this->getInstalledPackages(),
      array_flip(static::getCorePackageList())
    );

    // If drupal/core-recommended is present, it supersedes drupal/core, since
    // drupal/core will always be one of its direct dependencies.
    if (array_key_exists('drupal/core-recommended', $core_packages)) {
      unset($core_packages['drupal/core']);
    }
    return $core_packages;
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

  /**
   * Returns installed package data from Composer's `installed.php`.
   *
   * @return array
   *   The installed package data as represented in Composer's `installed.php`,
   *   keyed by package name.
   */
  private function getInstalledPackagesData(): array {
    $installed_php = implode(DIRECTORY_SEPARATOR, [
      // Composer returns the absolute path to the vendor directory by default.
      $this->getComposer()->getConfig()->get('vendor-dir'),
      'composer',
      'installed.php',
    ]);
    $data = include $installed_php;
    return $data['versions'];
  }

  /**
   * Returns the Drupal project name for a given Composer package.
   *
   * @param string $package_name
   *   The name of the package.
   *
   * @return string|null
   *   The name of the Drupal project installed by the package, or NULL if:
   *   - The package is not installed.
   *   - The package is not of a supported type (one of `drupal-module`,
   *     `drupal-theme`, or `drupal-profile`).
   *   - The package name does not begin with `drupal/`.
   *   - The project name could not otherwise be determined.
   */
  public function getProjectForPackage(string $package_name): ?string {
    $data = $this->getInstalledPackagesData();

    if (array_key_exists($package_name, $data)) {
      $package = $data[$package_name];

      $supported_package_types = [
        'drupal-module',
        'drupal-theme',
        'drupal-profile',
      ];
      // Only consider packages which are packaged by drupal.org and will be
      // known to the core Update module.
      if (str_starts_with($package_name, 'drupal/') && in_array($package['type'], $supported_package_types, TRUE)) {
        return $this->scanForProjectName($package['install_path']);
      }
    }
    return NULL;
  }

  /**
   * Returns the package name for a given Drupal project.
   *
   * @param string $project_name
   *   The name of the project.
   *
   * @return string|null
   *   The name of the Composer package which installs the project, or NULL if
   *   it could not be determined.
   */
  public function getPackageForProject(string $project_name): ?string {
    $installed = $this->getInstalledPackagesData();

    // If we're lucky, the package name is the project name, prefixed with
    // `drupal/`.
    if (array_key_exists("drupal/$project_name", $installed)) {
      return "drupal/$project_name";
    }

    $installed = array_keys($installed);
    foreach ($installed as $package_name) {
      if ($this->getProjectForPackage($package_name) === $project_name) {
        return $package_name;
      }
    }
    return NULL;
  }

  /**
   * Scans a given path to determine the Drupal project name.
   *
   * The path will be scanned for `.info.yml` files containing a `project` key.
   *
   * @param string $path
   *   The path to scan.
   *
   * @return string|null
   *   The name of the project, as declared in the first found `.info.yml` which
   *   contains a `project` key, or NULL if none was found.
   */
  private function scanForProjectName(string $path): ?string {
    $iterator = new \RecursiveDirectoryIterator($path);
    $iterator = new \RecursiveIteratorIterator($iterator);
    $iterator = new \RegexIterator($iterator, '/.+\.info\.yml$/', \RecursiveRegexIterator::GET_MATCH);

    foreach ($iterator as $match) {
      $info = file_get_contents($match[0]);
      $info = Yaml::decode($info);

      if (is_string($info['project']) && !empty($info['project'])) {
        return $info['project'];
      }
    }
    return NULL;
  }

}
