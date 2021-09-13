<?php

namespace Drupal\package_manager;

use PhpTuf\ComposerStager\Infrastructure\Process\ProcessFactory as StagerProcessFactory;
use PhpTuf\ComposerStager\Infrastructure\Process\ProcessFactoryInterface;
use Symfony\Component\Process\Process;

/**
 * Defines a process factory which sets the COMPOSER_HOME environment variable.
 *
 * @todo Figure out how to do this in composer_stager.
 */
final class ProcessFactory implements ProcessFactoryInterface {

  /**
   * The decorated process factory.
   *
   * @var \PhpTuf\ComposerStager\Infrastructure\Process\ProcessFactoryInterface
   */
  private $decorated;

  /**
   * Constructs a ProcessFactory object.
   */
  public function __construct() {
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
    }
    // Ensure that the running PHP binary is in the PATH.
    $env['PATH'] = $this->getEnv('PATH') . ':' . dirname(PHP_BINARY);
    return $process->setEnv($env);
  }

  /**
   * Returns the path to use as the COMPOSER_HOME environment variable.
   *
   * @return string
   *   The path which should be used as COMPOSER_HOME.
   */
  private function getComposerHomePath(): string {
    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = \Drupal::service('file_system');
    $home_path = $file_system->getTempDirectory() . '/automatic_updates_composer_home';
    if (!is_dir($home_path)) {
      mkdir($home_path);
    }
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
