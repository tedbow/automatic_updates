<?php

namespace Drupal\fixture_manipulator;

use Composer\Semver\VersionParser;
use Drupal\Component\FileSystem\FileSystem;
use Drupal\Component\Utility\NestedArray;
use PhpTuf\ComposerStager\Domain\Service\ProcessOutputCallback\ProcessOutputCallbackInterface;
use PhpTuf\ComposerStager\Domain\Service\ProcessRunner\ComposerRunnerInterface;
use Symfony\Component\Filesystem\Filesystem as SymfonyFileSystem;
use Drupal\Component\Serialization\Yaml;

/**
 * Manipulates a test fixture using Composer commands.
 *
 * The composer.json file CANNOT be safely created or modified using the
 * json_encode() function, because Composer does not use a real JSON parser — it
 * updates composer.json using \Composer\Json\JsonManipulator, which is known to
 * choke in a number of scenarios.
 *
 * @see https://www.drupal.org/i/3346628
 */
class FixtureManipulator {

  protected const PATH_REPO_STATE_KEY = self::class . '-path-repo-base';

  /**
   * Whether changes are currently being committed.
   *
   * @var bool
   */
  private bool $committingChanges = FALSE;

  /**
   * Arguments to manipulator functions.
   *
   * @var array
   */
  private array $manipulatorArguments = [];

  /**
   * Whether changes have been committed.
   *
   * @var bool
   */
  protected bool $committed = FALSE;

  /**
   * The fixture directory.
   *
   * @var string
   */
  protected string $dir;

  /**
   * Validate the fixtures still passes `composer validate`.
   */
  private function validateComposer(): void {
    /** @var \PhpTuf\ComposerStager\Domain\Service\ProcessRunner\ComposerRunnerInterface $runner */
    $runner = \Drupal::service(ComposerRunnerInterface::class);
    $runner->run([
      'validate',
      '--no-check-publish',
      '--with-dependencies',
      '--no-interaction',
      '--ansi',
      '--no-cache',
      "--working-dir={$this->dir}",
      // Unlike ComposerInspector::validate(), explicitly do NOT validate
      // plugins, to allow for testing edge cases.
      '--no-plugins',
      // @todo remove this after FixtureManipulator uses composer commands exclusively!
      '--no-check-lock',
      // Dummy packages are not meant for publishing, so do not validate that.
      '--no-check-publish',
      '--no-check-version',
    ]);
  }

  /**
   * Adds a package.
   *
   * @param array $package
   *   The package info that should be added to installed.json and
   *   installed.php. Must include the `name` and `type` keys.
   * @param bool $is_dev_requirement
   *   Whether or not the package is a development requirement.
   * @param bool $allow_plugins
   *   Whether or not to use the '--no-plugins' option.
   * @param array|null $extra_files
   *   An array extra files to create in the package. The keys are the file
   *   paths under package and values are the file contents.
   */
  public function addPackage(array $package, bool $is_dev_requirement = FALSE, bool $allow_plugins = FALSE, ?array $extra_files = NULL): self {
    if (!$this->committingChanges) {
      // To pass Composer validation all packages must have a version specified.
      if (!isset($package['version'])) {
        $package['version'] = '1.2.3';
      }
      $this->queueManipulation('addPackage', [$package, $is_dev_requirement, $allow_plugins, $extra_files]);
      return $this;
    }

    // Basic validation so we can defer the rest to `composer` commands.
    foreach (['name', 'type'] as $required_key) {
      if (!isset($package[$required_key])) {
        throw new \UnexpectedValueException("The '$required_key' is required when calling ::addPackage().");
      }
    }
    if (!preg_match('/\w+\/\w+/', $package['name'])) {
      throw new \UnexpectedValueException(sprintf("'%s' is not a valid package name.", $package['name']));
    }

    // `composer require` happily will re-require already required packages.
    // Prevent test authors from thinking this has any effect when it does not.
    $json = $this->runComposerCommand(['show', '--name-only', '--format=json'])->stdout;
    $installed_package_names = array_column(json_decode($json)->installed, 'name');
    if (in_array($package['name'], $installed_package_names)) {
      throw new \LogicException(sprintf("Expected package '%s' to not be installed, but it was.", $package['name']));
    }

    $repo_path = $this->addRepository($package);
    if (is_null($extra_files) && isset($package['type']) && in_array($package['type'], ['drupal-module', 'drupal-theme', 'drupal-profile'], TRUE)) {
      // For Drupal projects if no files are provided create an info.yml file
      // that assumes the project and package names match.
      [, $package_name] = explode('/', $package['name']);
      $project_name = str_replace('-', '_', $package_name);
      $project_info_data = [
        'name' => $package['name'],
        'project' => $project_name,
      ];
      $extra_files["$project_name.info.yml"] = Yaml::encode($project_info_data);
    }
    if (!empty($extra_files)) {
      $fs = new SymfonyFileSystem();
      foreach ($extra_files as $file_name => $file_contents) {
        if (str_contains($file_name, DIRECTORY_SEPARATOR)) {
          $file_dir = dirname("$repo_path/$file_name");
          if (!is_dir($file_dir)) {
            $fs->mkdir($file_dir);
          }
        }
        file_put_contents("$repo_path/$file_name", $file_contents);
      }
    }
    $command_options = ['require', "{$package['name']}:{$package['version']}"];
    if ($is_dev_requirement) {
      $command_options[] = '--dev';
    }
    // Unlike ComposerInspector::validate(), explicitly do NOT validate plugins.
    if (!$allow_plugins) {
      $command_options[] = '--no-plugins';
    }
    $this->runComposerCommand($command_options);
    return $this;
  }

  /**
   * Modifies a package's installed info.
   *
   * @todo Since ::setVersion() is not longer calling this method the only test
   *   the is using this that is not just testing this method itself is
   *   \Drupal\Tests\automatic_updates\Kernel\StatusCheck\ScaffoldFilePermissionsValidatorTest::testScaffoldFilesChanged
   *   That test is passing, so we could leave it, then we have to leave
   *   ::setPackage() which is very complicated. Will leave notes on
   *   testScaffoldFilesChanged() how we might solve that with composer commands
   *   instead of this method.
   *
   * @param string $name
   *   The name of the package to modify.
   * @param array $package
   *   The package info that should be updated in installed.json and
   *   installed.php.
   */
  public function modifyPackage(string $name, array $package): self {
    if (!$this->committingChanges) {
      $this->queueManipulation('modifyPackage', func_get_args());
      return $this;
    }
    $this->setPackage($name, $package, TRUE);
    return $this;
  }

  /**
   * Sets a package version.
   *
   * @param string $package_name
   *   The package name.
   * @param string $version
   *   The version.
   * @param bool $is_dev_requirement
   *   Whether or not the package is a development requirement.
   *
   * @return $this
   */
  public function setVersion(string $package_name, string $version, bool $is_dev_requirement = FALSE): self {
    if (!$this->committingChanges) {
      $this->queueManipulation('setVersion', func_get_args());
      return $this;
    }
    $package = [
      'name' => $package_name,
      'version' => $version,
    ];
    $this->addRepository($package);
    $this->runComposerCommand(array_filter(['require', "$package_name:$version", $is_dev_requirement ? '--dev' : NULL]));
    return $this;
  }

  /**
   * Removes a package.
   *
   * @param string $name
   *   The name of the package to remove.
   * @param bool $is_dev_requirement
   *   Whether or not the package is a developer requirement.
   */
  public function removePackage(string $name, bool $is_dev_requirement = FALSE): self {
    if (!$this->committingChanges) {
      $this->queueManipulation('removePackage', func_get_args());
      return $this;
    }

    $output = $this->runComposerCommand(array_filter(['remove', $name, $is_dev_requirement ? '--dev' : NULL, '--no-update']));
    // `composer remove` will not set exit code 1 whenever a non-required
    // package is being removed.
    // @see \Composer\Command\RemoveCommand
    if (str_contains($output->stderr, 'not required in your composer.json and has not been removed')) {
      $output->stderr = str_replace("./composer.json has been updated\n", '', $output->stderr);
      throw new \LogicException($output->stderr);
    }

    // Make sure that `installed.json` & `installed.php` are updated.
    // @todo Remove this when ComposerUtility gets removed.
    $this->runComposerCommand(['update', $name]);
    return $this;
  }

  /**
   * Changes a package's installation information in a particular directory.
   *
   * This function is internal and should not be called directly. Use
   * ::addPackage(), ::modifyPackage(), and ::removePackage() instead.
   *
   * @todo Remove this method once ::modifyPackage() doesn't call it.
   *
   * @param string $pretty_name
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
  private function setPackage(string $pretty_name, ?array $package, bool $should_exist, ?bool $is_dev_requirement = NULL): void {
    // @see \Composer\Package\BasePackage::__construct()
    $name = strtolower($pretty_name);

    if ($should_exist && isset($is_dev_requirement)) {
      throw new \LogicException('Changing an existing project to a dev requirement is not supported');
    }
    $composer_folder = $this->dir . '/vendor/composer';

    $file = $composer_folder . '/installed.json';
    self::ensureFilePathIsWritable($file);

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
    $expected_package_message = $should_exist
      ? "Expected package '$pretty_name' to be installed, but it wasn't."
      : "Expected package '$pretty_name' to not be installed, but it was.";
    if ($should_exist !== isset($position)) {
      throw new \LogicException($expected_package_message);
    }

    if ($package) {
      $package = ['name' => $pretty_name] + $package;
      $install_json_package = $package;
      // Composer will use 'version_normalized', if present, to determine the
      // version number.
      if (isset($install_json_package['version']) && !isset($install_json_package['version_normalized'])) {
        $parser = new VersionParser();
        $install_json_package['version_normalized'] = $parser->normalize($install_json_package['version']);
      }
    }

    if (isset($position)) {
      // If we're going to be updating the package data, merge the incoming data
      // into what we already have.
      if ($package) {
        $install_json_package = $install_json_package + $data['packages'][$position];
      }

      // If `$package === NULL`, the existing package should be removed.
      if ($package === NULL) {
        array_splice($data['packages'], $position, 1);
        $is_existing_dev_package = in_array($name, $data['dev-package-names'], TRUE);
        $data['dev-package-names'] = array_diff($data['dev-package-names'], [$name]);
        $data['dev-package-names'] = array_values($data['dev-package-names']);
      }
    }
    // Add the package back to the list, if we have data for it.
    if (isset($install_json_package)) {
      // If it previously existed, put it back in the previous position.
      if ($position) {
        $data['packages'][$i] = $install_json_package;
      }
      // Otherwise, it must be new: append it to the list.
      else {
        $data['packages'][] = $install_json_package;
      }

      if ($is_dev_requirement || !empty($is_existing_dev_package)) {
        $data['dev-package-names'][] = $name;
      }
    }
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    self::ensureFilePathIsWritable($file);

    $file = $composer_folder . '/installed.php';
    self::ensureFilePathIsWritable($file);

    $data = require $file;

    // Ensure that we actually expect to find the package already installed (or
    // not).
    if ($should_exist !== isset($data['versions'][$name])) {
      throw new \LogicException($expected_package_message);
    }
    if ($package) {
      $install_php_package = $should_exist ?
        NestedArray::mergeDeep($data['versions'][$name], $package) :
        $package;
      $data['versions'][$name] = $install_php_package;
    }
    else {
      unset($data['versions'][$name]);
    }

    $data = var_export($data, TRUE);
    file_put_contents($file, "<?php\nreturn $data;");
  }

  /**
   * Adds a project at a path.
   *
   * @param string $path
   *   The path.
   * @param string|null $project_name
   *   (optional) The project name. If none is specified the last part of the
   *   path will be used.
   * @param string|null $file_name
   *   (optional) The file name. If none is specified the project name will be
   *   used.
   */
  public function addProjectAtPath(string $path, ?string $project_name = NULL, ?string $file_name = NULL): self {
    if (!$this->committingChanges) {
      $this->queueManipulation('addProjectAtPath', func_get_args());
      return $this;
    }
    $path = $this->dir . "/$path";
    if (file_exists($path)) {
      throw new \LogicException("'$path' path already exists.");
    }
    $fs = new SymfonyFileSystem();
    $fs->mkdir($path);
    if ($project_name === NULL) {
      $project_name = basename($path);
    }
    if ($file_name === NULL) {
      $file_name = "$project_name.info.yml";
    }
    file_put_contents("$path/$file_name", Yaml::encode(['project' => $project_name]));
    return $this;
  }

  /**
   * Modifies core packages.
   *
   * @param string $version
   *   Target version.
   */
  public function setCorePackageVersion(string $version): self {
    $this->setVersion('drupal/core', $version);
    $this->setVersion('drupal/core-recommended', $version);
    $this->setVersion('drupal/core-dev', $version);
    return $this;
  }

  /**
   * Modifies a package's installed info.
   *
   * @param array $additional_config
   *   The configuration to add.
   */
  public function addConfig(array $additional_config): self {
    if (empty($additional_config)) {
      throw new \InvalidArgumentException('No config to add.');
    }

    if (!$this->committingChanges) {
      $this->queueManipulation('addConfig', func_get_args());
      return $this;
    }
    $clean_value = function ($value) {
      return $value === FALSE ? 'false' : $value;
    };

    foreach ($additional_config as $key => $value) {
      $command = ['config'];
      if (is_array($value)) {
        $value = json_encode($value, JSON_UNESCAPED_SLASHES);
        $command[] = '--json';
      }
      else {
        $value = $clean_value($value);
      }
      $command[] = $key;
      $command[] = $value;
      $this->runComposerCommand($command);
    }
    $this->runComposerCommand(['update', '--lock']);

    return $this;
  }

  /**
   * Commits the changes to the directory.
   */
  public function commitChanges(string $dir): void {
    $this->doCommitChanges($dir);
    $this->committed = TRUE;
  }

  /**
   * Commits all the changes.
   *
   * @param string $dir
   *   The directory to commit the changes to.
   */
  final protected function doCommitChanges(string $dir): void {
    if ($this->committed) {
      throw new \BadMethodCallException('Already committed.');
    }
    $this->dir = $dir;
    $this->setUpRepos();
    $this->committingChanges = TRUE;
    $manipulator_arguments = $this->getQueuedManipulationItems();
    $this->clearQueuedManipulationItems();
    foreach ($manipulator_arguments as $method => $argument_sets) {
      // @todo Attempt to make fewer Composer calls in
      //   https://drupal.org/i/3345639.
      foreach ($argument_sets as $argument_set) {
        $this->{$method}(...$argument_set);
      }
    }
    $this->committed = TRUE;
    $this->committingChanges = FALSE;
    $this->validateComposer();
  }

  /**
   * Ensure that changes were committed before object is destroyed.
   */
  public function __destruct() {
    if (!$this->committed && !empty($this->manipulatorArguments)) {
      throw new \LogicException('commitChanges() must be called.');
    }
  }

  /**
   * Ensures a path is writable.
   *
   * @param string $path
   *   The path.
   */
  private static function ensureFilePathIsWritable(string $path): void {
    if (!is_writable($path)) {
      throw new \LogicException("'$path' is not writable.");
    }
  }

  /**
   * Creates an empty .git folder after being provided a path.
   */
  public function addDotGitFolder(string $path): self {
    if (!$this->committingChanges) {
      $this->queueManipulation('addDotGitFolder', func_get_args());
      return $this;
    }
    $fs = new SymfonyFileSystem();
    $git_directory_path = $path . "/.git";
    if (!is_dir($git_directory_path)) {
      $fs->mkdir($git_directory_path);
    }
    else {
      throw new \LogicException("A .git directory already exists at $path.");
    }
    return $this;
  }

  /**
   * Queues manipulation arguments to be called in ::doCommitChanges().
   *
   * @param string $method
   *   The method name.
   * @param array $arguments
   *   The arguments.
   */
  protected function queueManipulation(string $method, array $arguments): void {
    $this->manipulatorArguments[$method][] = $arguments;
  }

  /**
   * Clears all queued manipulation items.
   */
  protected function clearQueuedManipulationItems(): void {
    $this->manipulatorArguments = [];
  }

  /**
   * Gets all queued manipulation items.
   *
   * @return array
   *   The queued manipulation items as set by calls to ::queueManipulation().
   */
  protected function getQueuedManipulationItems(): array {
    return $this->manipulatorArguments;
  }

  protected function runComposerCommand(array $command_options): ProcessOutputCallbackInterface {
    $plain_output = new class() implements ProcessOutputCallbackInterface {
      public string $stdout = '';
      public string $stderr = '';

      /**
       * {@inheritdoc}
       */
      public function __invoke(string $type, string $buffer): void {
        if ($type === self::OUT) {
          $this->stdout .= $buffer;
          return;
        }
        elseif ($type === self::ERR) {
          $this->stderr .= $buffer;
          return;
        }
        throw new \InvalidArgumentException("Unsupported output type: '$type'");
      }

    };
    /** @var \PhpTuf\ComposerStager\Domain\Service\ProcessRunner\ComposerRunnerInterface $runner */
    $runner = \Drupal::service(ComposerRunnerInterface::class);
    $command_options[] = "--working-dir={$this->dir}";
    $runner->run($command_options, $plain_output);
    return $plain_output;
  }

  /**
   * Transform the received $package into options for `composer init`.
   *
   * @param array $package
   *   A Composer package definition.
   *
   * @return array
   *   The corresponding `composer init` options.
   */
  private static function getComposerInitOptionsForPackage(array $package): array {
    return array_filter(array_map(function ($k, $v) {
      switch ($k) {
        case 'name':
        case 'description':
        case 'type':
          return "--$k=$v";

        case 'require':
        case 'require-dev':
          $requirements = array_map(
            fn(string $req_package, string $req_version): string => "$req_package:$req_version",
            array_keys($v),
            array_values($v)
          );
          return "--$k=" . implode(',', $requirements);

        case 'version':
          // This gets set in the repository metadata itself.
          return NULL;

        case 'extra':
          // Cannot be set using `composer init`, only `composer config` can.
          return NULL;

        default:
          throw new \InvalidArgumentException($k);
      }
    }, array_keys($package), array_values($package)));
  }

  /**
   * Adds a path repository.
   *
   * @param array $package
   *   The package.
   *
   * @return string
   *   The repository path.
   */
  private function addRepository(array $package): string {
    $name = $package['name'];
    $path_repo_base = \Drupal::state()->get(self::PATH_REPO_STATE_KEY);
    $repo_path = "$path_repo_base/" . str_replace('/', '--', $name);
    $fs = new SymfonyFileSystem();
    if (!is_dir($repo_path)) {
      // Create the repo if it does not exist.
      $fs->mkdir($repo_path);
      // Switch the working directory from project root to repo path.
      $project_root_dir = $this->dir;
      $this->dir = $repo_path;
      // Create a composer.json file using `composer init`.
      $this->runComposerCommand(['init', ...static::getComposerInitOptionsForPackage($package)]);
      // Set the `extra` property in the generated composer.json file using
      // `composer config`, because `composer init` does not support it.
      foreach ($package['extra'] ?? [] as $extra_property => $extra_value) {
        $this->runComposerCommand(['config', "extra.$extra_property", '--json', json_encode($extra_value, JSON_UNESCAPED_SLASHES)]);
      }
      // Restore the project root as the working directory.
      $this->dir = $project_root_dir;
    }

    // Register the repository, keyed by package name. This ensures each package
    // is registered only once and its version can be updated.
    // @todo Should we create 1 repo per version.
    $repository = json_encode([
      'type' => 'path',
      'url' => $repo_path,
      'options' => [
        'symlink' => FALSE,
        'versions' => [
          $name => $package['version'],
        ],
      ],
    ], JSON_UNESCAPED_SLASHES);
    $this->runComposerCommand(['config', "repo.$name", $repository]);

    return $repo_path;
  }

  /**
   * Sets up the path repos at absolute paths.
   */
  public function setUpRepos(): void {
    // Some of the test coverage for FixtureManipulator tests edge cases for
    // making installed.php invalid, and those test fixtures do NOT have a
    // composer.json because ComposerUtility didn't look at that!
    // @todo Remove this early return when ComposerUtility gets removed along
    // with that edge case test coverage.
    // @see fixtures/FixtureUtilityTraitTest/missing_installed_php
    if (!file_exists($this->dir . '/composer.json')) {
      return;
    }
    $fs = new SymfonyFileSystem();
    $path_repo_base = \Drupal::state()->get(self::PATH_REPO_STATE_KEY);
    if (empty($path_repo_base)) {
      $path_repo_base = FileSystem::getOsTemporaryDirectory() . '/base-repo-' . microtime(TRUE) . rand(0, 10000);
      \Drupal::state()->set(self::PATH_REPO_STATE_KEY, $path_repo_base);
      // Copy the existing repos that were used to make the fixtures into the
      // new folder.
      $fs->mirror(__DIR__ . '/../../../fixtures/path_repos', $path_repo_base);
    }
    // Update all the repos in the composer.json file to point to the new
    // repos at the absolute path.
    $composer_json = file_get_contents($this->dir . '/composer.json');
    file_put_contents($this->dir . '/composer.json', str_replace('../path_repos/', "$path_repo_base/", $composer_json));
    $this->runComposerCommand(['install']);
  }

}
