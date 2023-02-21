<?php

declare(strict_types = 1);

namespace Drupal\package_manager;

use PhpTuf\ComposerStager\Domain\Exception\RuntimeException;
use PhpTuf\ComposerStager\Domain\Service\ProcessOutputCallback\ProcessOutputCallbackInterface;
use PhpTuf\ComposerStager\Domain\Service\ProcessRunner\ComposerRunnerInterface;

/**
 * Defines a class to get information from Composer.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
final class ComposerInspector {

  /**
   * The JSON process output callback.
   *
   * @var \Drupal\package_manager\JsonProcessOutputCallback
   */
  private JsonProcessOutputCallback $jsonCallback;

  /**
   * An array of installed packages lists, keyed by `composer.lock` file path.
   *
   * @var \Drupal\package_manager\InstalledPackagesList[]
   */
  private array $packageLists = [];

  /**
   * The hashes of composer.lock files, keyed by directory path.
   *
   * @var string[]
   */
  private array $lockFileHashes = [];

  /**
   * Constructs a ComposerInspector object.
   *
   * @param \PhpTuf\ComposerStager\Domain\Service\ProcessRunner\ComposerRunnerInterface $runner
   *   The Composer runner service from Composer Stager.
   */
  public function __construct(private ComposerRunnerInterface $runner) {
    $this->jsonCallback = new JsonProcessOutputCallback();
  }

  /**
   * Returns a config value from Composer.
   *
   * @param string $key
   *   The config key to get.
   * @param string $working_dir
   *   The working directory in which to run Composer.
   *
   * @return string|null
   *   The output data. Note that the caller must know the shape of the
   *   requested key's value: if it's a string, no further processing is needed,
   *   but if it is a boolean, an array or a map, JSON decoding should be
   *   applied.
   *
   * @see \Composer\Command\ConfigCommand::execute()
   */
  public function getConfig(string $key, string $working_dir) : ?string {
    // For whatever reason, PHPCS thinks that $output is not used, even though
    // it very clearly *is*. So, shut PHPCS up for the duration of this method.
    // phpcs:disable DrupalPractice.CodeAnalysis.VariableAnalysis.UnusedVariable
    $callback = new class () implements ProcessOutputCallbackInterface {

      /**
       * The command output.
       *
       * @var string
       */
      public string $output = '';

      /**
       * {@inheritdoc}
       */
      public function __invoke(string $type, string $buffer): void {
        if ($type === ProcessOutputCallbackInterface::OUT) {
          $this->output .= trim($buffer);
        }
      }

    };
    // phpcs:enable
    try {
      $this->runner->run(['config', $key, "--working-dir=$working_dir"], $callback);
    }
    catch (RuntimeException $e) {
      // Assume any error from `composer config` is about an undefined key-value
      // pair which may have a known default value.
      // @todo Remove this once https://github.com/composer/composer/issues/11302 lands and ships in a composer release.
      switch ($key) {
        // @see https://getcomposer.org/doc/04-schema.md#minimum-stability
        case 'minimum-stability':
          return 'stable';

        default:
          // Otherwise, re-throw the exception.
          throw $e;
      }
    }
    return $callback->output;
  }

  /**
   * Returns the current Composer version.
   *
   * @param string $working_dir
   *   The working directory in which to run Composer.
   *
   * @return string
   *   The Composer version.
   *
   * @throws \UnexpectedValueException
   *   Thrown if the expect data format is not found.
   */
  public function getVersion(string $working_dir): string {
    $this->runner->run(['--format=json', "--working-dir=$working_dir"], $this->jsonCallback);
    $data = $this->jsonCallback->getOutputData();
    if (isset($data['application']['name'])
      && isset($data['application']['version'])
      && $data['application']['name'] === 'Composer'
      && is_string($data['application']['version'])) {
      return $data['application']['version'];
    }
    throw new \UnexpectedValueException('Unable to determine Composer version');
  }

  /**
   * Returns the installed packages list.
   *
   * @param string $working_dir
   *   The working directory in which to run Composer. Should contain a
   *   `composer.lock` file.
   *
   * @return \Drupal\package_manager\InstalledPackagesList
   *   The installed packages list for the directory.
   */
  public function getInstalledPackagesList(string $working_dir): InstalledPackagesList {
    $working_dir = realpath($working_dir);
    $lock_file_path = $working_dir . DIRECTORY_SEPARATOR . 'composer.lock';

    // Computing the list of installed packages is an expensive operation, so
    // only do it if the lock file has changed.
    $lock_file_hash = hash_file('sha256', $lock_file_path);
    if (array_key_exists($lock_file_path, $this->lockFileHashes) && $this->lockFileHashes[$lock_file_path] !== $lock_file_hash) {
      unset($this->packageLists[$lock_file_path]);
    }
    $this->lockFileHashes[$lock_file_path] = $lock_file_hash;

    if (!isset($this->packageLists[$lock_file_path])) {
      $packages_data = $this->show($working_dir);

      // The package type is not available using `composer show` for listing
      // packages. To avoiding making many calls to `composer show package-name`
      // load the lock file data to get the `type` key.
      // @todo Remove all of this when
      //   https://github.com/composer/composer/pull/11340 lands and we bump our
      //   Composer requirement accordingly.
      $lock_content = file_get_contents($lock_file_path);
      $lock_data = json_decode($lock_content, TRUE, 512, JSON_THROW_ON_ERROR);
      $lock_packages = array_merge($lock_data['packages'] ?? [], $lock_data['packages-dev'] ?? []);
      foreach ($lock_packages as $lock_package) {
        $name = $lock_package['name'];
        if (isset($packages_data[$name]) && isset($lock_package['type'])) {
          $packages_data[$name]['type'] = $lock_package['type'];
        }
      }

      $packages_data = array_map(fn (array $data) => InstalledPackage::createFromArray($data), $packages_data);
      $this->packageLists[$lock_file_path] = new InstalledPackagesList($packages_data);
    }
    return $this->packageLists[$lock_file_path];
  }

  /**
   * Gets the installed packages data from running `composer show`.
   *
   * @param string $working_dir
   *   The directory in which to run `composer show`.
   *
   * @return array[]
   *   The installed packages data, keyed by package name.
   */
  private function show(string $working_dir): array {
    $data = [];
    $options = ['show', '--format=json', "--working-dir={$working_dir}"];

    // We don't get package installation paths back from `composer show` unless
    // we explicitly pass the --path option to it. However, for some
    // inexplicable reason, that option hides *other* relevant information
    // about the installed packages. So, to work around this maddening quirk, we
    // call `composer show` once without the --path option, and once with it,
    // then merge the results together.
    $this->runner->run($options, $this->jsonCallback);
    $output = $this->jsonCallback->getOutputData();
    foreach ($output['installed'] as $installed_package) {
      $data[$installed_package['name']] = $installed_package;
    }

    $options[] = '--path';
    $this->runner->run($options, $this->jsonCallback);
    $output = $this->jsonCallback->getOutputData();
    foreach ($output['installed'] as $installed_package) {
      $data[$installed_package['name']]['path'] = $installed_package['path'];
    }
    return $data;
  }

}
