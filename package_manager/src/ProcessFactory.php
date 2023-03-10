<?php

declare(strict_types = 1);

namespace Drupal\package_manager;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use PhpTuf\ComposerStager\Infrastructure\Factory\Process\ProcessFactoryInterface;
use PhpTuf\ComposerStager\Infrastructure\Factory\Process\ProcessFactory as StagerProcessFactory;
use Symfony\Component\Process\Process;

// cspell:ignore BINDIR

/**
 * Defines a process factory which sets the COMPOSER_HOME environment variable.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
final class ProcessFactory implements ProcessFactoryInterface {

  /**
   * The decorated process factory.
   *
   * @var \PhpTuf\ComposerStager\Infrastructure\Factory\Process\ProcessFactoryInterface
   */
  private $decorated;

  /**
   * Constructs a ProcessFactory object.
   *
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   */
  public function __construct(private FileSystemInterface $fileSystem, private ConfigFactoryInterface $configFactory) {
    $this->decorated = new StagerProcessFactory();
  }

  /**
   * Returns the value of an environment variable.
   *
   * @param string $variable
   *   The name of the variable.
   *
   * @return mixed
   *   The value of the variable.
   */
  private function getEnv(string $variable) {
    if (function_exists('apache_getenv')) {
      return apache_getenv($variable);
    }
    return getenv($variable);
  }

  /**
   * {@inheritdoc}
   */
  public function create(array $command): Process {
    $process = $this->decorated->create($command);

    $env = $process->getEnv();
    if ($this->isComposerCommand($command)) {
      $env['COMPOSER_HOME'] = $this->getComposerHomePath();
      // Work around Composer not being designed to be run massively in parallel
      // which it may in the context of Package Manager, at least for tests. It
      // is trivial to work around though: create a unique temporary directory
      // per process.
      // @see https://www.drupal.org/i/3338789#comment-14961390
      // @see https://github.com/composer/composer/commit/28e9193e9ebde743c19f334a7294830fc6429d06
      // @see https://github.com/composer/composer/commit/43eb471ec293822d377b618a4a14d8d3651f5d13
      static $race_condition_proof_tmpdir;
      if (!isset($race_condition_proof_tmpdir)) {
        $race_condition_proof_tmpdir = sys_get_temp_dir() . '/' . getmypid();
        // The same PHP process may run multiple tests: create the directory
        // only once.
        if (!is_dir($race_condition_proof_tmpdir)) {
          mkdir($race_condition_proof_tmpdir);
        }
      }
      $env['TMPDIR'] = $race_condition_proof_tmpdir;
    }
    // Ensure that the current PHP installation is the first place that will be
    // searched when looking for the PHP interpreter.
    $env['PATH'] = static::getPhpDirectory() . ':' . $this->getEnv('PATH');
    return $process->setEnv($env);
  }

  /**
   * Returns the directory which contains the PHP interpreter.
   *
   * @return string
   *   The path of the directory containing the PHP interpreter. If the server
   *   is running in a command-line interface, the directory portion of
   *   PHP_BINARY is returned; otherwise, the compile-time PHP_BINDIR is.
   *
   * @see php_sapi_name()
   * @see https://www.php.net/manual/en/reserved.constants.php
   */
  protected static function getPhpDirectory(): string {
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server') {
      return dirname(PHP_BINARY);
    }
    return PHP_BINDIR;
  }

  /**
   * Returns the path to use as the COMPOSER_HOME environment variable.
   *
   * @return string
   *   The path which should be used as COMPOSER_HOME.
   */
  private function getComposerHomePath(): string {
    $home_path = $this->fileSystem->getTempDirectory();
    $home_path .= '/package_manager_composer_home-';
    $home_path .= $this->configFactory->get('system.site')->get('uuid');
    $this->fileSystem->prepareDirectory($home_path, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    return $home_path;
  }

  /**
   * Determines if a command is running Composer.
   *
   * @param string[] $command
   *   The command parts.
   *
   * @return bool
   *   TRUE if the command is running Composer, FALSE otherwise.
   */
  private function isComposerCommand(array $command): bool {
    $executable = $command[0];
    $executable_parts = explode('/', $executable);
    $file = array_pop($executable_parts);
    return strpos($file, 'composer') === 0;
  }

}
