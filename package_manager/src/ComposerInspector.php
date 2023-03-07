<?php

declare(strict_types = 1);

namespace Drupal\package_manager;

use Composer\Semver\Semver;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use PhpTuf\ComposerStager\Domain\Exception\PreconditionException;
use PhpTuf\ComposerStager\Domain\Exception\RuntimeException;
use PhpTuf\ComposerStager\Domain\Service\Precondition\ComposerIsAvailableInterface;
use PhpTuf\ComposerStager\Domain\Service\ProcessOutputCallback\ProcessOutputCallbackInterface;
use PhpTuf\ComposerStager\Domain\Service\ProcessRunner\ComposerRunnerInterface;
use PhpTuf\ComposerStager\Infrastructure\Factory\Path\PathFactoryInterface;

/**
 * Defines a class to get information from Composer.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
class ComposerInspector {

  use StringTranslationTrait;

  /**
   * The JSON process output callback.
   *
   * @var \Drupal\package_manager\JsonProcessOutputCallback
   */
  private JsonProcessOutputCallback $jsonCallback;

  /**
   * Statically cached installed package lists, keyed by directory.
   *
   * @var \Drupal\package_manager\InstalledPackagesList[]
   */
  private array $packageLists = [];

  /**
   * A semantic version constraint for the supported version(s) of Composer.
   *
   * Only versions supported by Composer are supported: the LTS and the latest
   * minor version. Those are currently 2.2 and 2.5.
   *
   * @see https://endoflife.date/composer
   *
   * Note that Composer <= 2.2.11 is not supported anymore due to a security
   * vulnerability.
   *
   * @see https://blog.packagist.com/cve-2022-24828-composer-command-injection-vulnerability/
   *
   * @var string
   */
  final public const SUPPORTED_VERSION = '~2.2.12 || ^2.5';

  /**
   * Constructs a ComposerInspector object.
   *
   * @param \PhpTuf\ComposerStager\Domain\Service\ProcessRunner\ComposerRunnerInterface $runner
   *   The Composer runner service from Composer Stager.
   * @param \PhpTuf\ComposerStager\Domain\Service\Precondition\ComposerIsAvailableInterface $composerIsAvailable
   *   The Composer Stager precondition to ensure that Composer is available.
   * @param \PhpTuf\ComposerStager\Infrastructure\Factory\Path\PathFactoryInterface $pathFactory
   *   The path factory service from Composer Stager.
   */
  public function __construct(private ComposerRunnerInterface $runner, private ComposerIsAvailableInterface $composerIsAvailable, private PathFactoryInterface $pathFactory) {
    $this->jsonCallback = new JsonProcessOutputCallback();
  }

  /**
   * Checks that Composer commands can be run.
   *
   * @param string $working_dir
   *   The directory in which Composer will be run.
   *
   * @throws \Exception
   *   If any of the following are true:
   *   - The Composer executable is not available.
   *   - composer.json does not exist in the given directory.
   *   - composer.lock does not exist in the given directory.
   *   - The detected version of Composer is not supported.
   */
  public function validate(string $working_dir): void {
    $messages = [];

    // Ensure the Composer executable is available. For performance reasons,
    // statically cache the result, since it's unlikely to change during the
    // current request. If $unavailable_message is NULL, it means we haven't
    // done this check yet. If it's FALSE, it means we did the check and there
    // were no errors; and, if it's a string, it's the error message we received
    // the last time we did this check.
    static $unavailable_message;
    if ($unavailable_message === NULL) {
      try {
        // The "Composer is available" precondition requires active and stage
        // directories, but they don't actually matter to it, nor do path
        // exclusions, so dummies can be passed for simplicity.
        $active_dir = $this->pathFactory::create($working_dir);
        $stage_dir = $active_dir;

        $this->composerIsAvailable->assertIsFulfilled($active_dir, $stage_dir);
        $unavailable_message = FALSE;
      }
      catch (PreconditionException $e) {
        $unavailable_message = $e->getMessage();
      }
    }
    if ($unavailable_message) {
      $messages[] = $unavailable_message;
    }

    // The detected version of Composer is unlikely to change during the
    // current request, so statically cache it. If $unsupported_message is NULL,
    // it means we haven't done this check yet. If it's FALSE, it means we did
    // the check and there were no errors; and, if it's a string, it's the error
    // message we received the last time we did this check.
    static $unsupported_message;
    if ($unsupported_message === NULL) {
      try {
        $detected_version = $this->getVersion($working_dir);

        if (Semver::satisfies($detected_version, static::SUPPORTED_VERSION)) {
          // We did the version check, and it did not produce an error message.
          $unsupported_message = FALSE;
        }
        else {
          $unsupported_message = $this->t('The detected Composer version, @version, does not satisfy <code>@constraint</code>.', [
            '@version' => $detected_version,
            '@constraint' => static::SUPPORTED_VERSION,
          ]);
        }
      }
      catch (\UnexpectedValueException $e) {
        $unsupported_message = $e->getMessage();
      }
    }
    if ($unsupported_message) {
      $messages[] = $unsupported_message;
    }

    // If either composer.json or composer.lock have changed, ensure the
    // directory is in a completely valid state, according to Composer.
    if ($this->invalidateCacheIfNeeded($working_dir)) {
      try {
        $this->runner->run([
          'validate',
          // @todo Check the lock file in https://drupal.org/i/3343827.
          '--no-check-lock',
          '--no-check-publish',
          '--with-dependencies',
          '--no-interaction',
          '--ansi',
          '--no-cache',
          "--working-dir=$working_dir",
        ]);
      }
      catch (RuntimeException $e) {
        $messages[] = $e->getMessage();
      }
    }

    // Check for the presence of composer.lock, because `composer validate`
    // doesn't expect it to exist, but we do (see ::getInstalledPackagesList()).
    if (!file_exists($working_dir . DIRECTORY_SEPARATOR . 'composer.lock')) {
      $messages[] = $this->t('composer.lock not found in @dir.', [
        '@dir' => $working_dir,
      ]);
    }

    if ($messages) {
      throw new \Exception(implode("\n", $messages));
    }
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
    $this->validate($working_dir);

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

        case 'extra':
          return '{}';

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
  private function getVersion(string $working_dir): string {
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
    $this->validate($working_dir);

    if (array_key_exists($working_dir, $this->packageLists)) {
      return $this->packageLists[$working_dir];
    }

    $packages_data = $this->show($working_dir);
    // The package type is not available using `composer show` for listing
    // packages. To avoiding making many calls to `composer show package-name`
    // load the lock file data to get the `type` key.
    // @todo Remove all of this when
    //   https://github.com/composer/composer/pull/11340 lands and we bump our
    //   Composer requirement accordingly.
    $lock_content = file_get_contents($working_dir . DIRECTORY_SEPARATOR . 'composer.lock');
    $lock_data = json_decode($lock_content, TRUE, 512, JSON_THROW_ON_ERROR);

    $lock_packages = array_merge($lock_data['packages'] ?? [], $lock_data['packages-dev'] ?? []);
    foreach ($lock_packages as $lock_package) {
      $name = $lock_package['name'];
      if (isset($packages_data[$name]) && isset($lock_package['type'])) {
        $packages_data[$name]['type'] = $lock_package['type'];
      }
    }
    $packages_data = array_map(fn (array $data) => InstalledPackage::createFromArray($data), $packages_data);

    $list = new InstalledPackagesList($packages_data);
    $this->packageLists[$working_dir] = $list;

    return $list;
  }

  /**
   * Returns the output of `composer show --self` in a directory.
   *
   * @param string $working_dir
   *   The directory in which to run Composer.
   *
   * @return array
   *   The parsed output of `composer show --self`.
   */
  public function getRootPackageInfo(string $working_dir): array {
    $this->validate($working_dir);

    $this->runner->run(['show', '--self', '--format=json', "--working-dir={$working_dir}"], $this->jsonCallback);
    return $this->jsonCallback->getOutputData();
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
    // $output['installed'] will not be set if no packages are installed.
    if (isset($output['installed'])) {
      foreach ($output['installed'] as $installed_package) {
        $data[$installed_package['name']] = $installed_package;
      }

      $options[] = '--path';
      $this->runner->run($options, $this->jsonCallback);
      $output = $this->jsonCallback->getOutputData();
      foreach ($output['installed'] as $installed_package) {
        $data[$installed_package['name']]['path'] = $installed_package['path'];
      }
    }

    return $data;
  }

  /**
   * Invalidates cached data if composer.json or composer.lock have changed.
   *
   * The following cached data may be invalidated:
   * - Installed package lists (see ::getInstalledPackageList()).
   *
   * @param string $working_dir
   *   A directory that contains a `composer.json` file, and optionally a
   *   `composer.lock`. If either file has changed since the last time this
   *   method was called, any cached data for the directory will be invalidated.
   *
   * @return bool
   *   TRUE if the cached data was invalidated, otherwise FALSE.
   */
  private function invalidateCacheIfNeeded(string $working_dir): bool {
    static $known_hashes = [];

    $invalidate = FALSE;
    foreach (['composer.json', 'composer.lock'] as $filename) {
      $known_hash = $known_hashes[$working_dir][$filename] ?? '';
      // If the file doesn't exist, hash_file() will return FALSE.
      $current_hash = @hash_file('sha256', $working_dir . DIRECTORY_SEPARATOR . $filename);

      if ($known_hash && $current_hash && hash_equals($known_hash, $current_hash)) {
        continue;
      }
      $known_hashes[$working_dir][$filename] = $current_hash;
      $invalidate = TRUE;
    }
    if ($invalidate) {
      unset($this->packageLists[$working_dir]);
    }
    return $invalidate;
  }

}
