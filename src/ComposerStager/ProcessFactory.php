<?php

namespace Drupal\automatic_updates\ComposerStager;

use PhpTuf\ComposerStager\Exception\LogicException;
use PhpTuf\ComposerStager\Infrastructure\Process\ProcessFactoryInterface;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * Defines a process factory which sets the COMPOSER_HOME environment variable.
 *
 * @todo Figure out how to do this in composer_stager.
 */
final class ProcessFactory implements ProcessFactoryInterface {

  /**
   * {@inheritdoc}
   */
  public function create(array $command): Process {
    try {
      if ($this->isComposerCommand($command)) {
        $process = new Process($command, NULL, ['COMPOSER_HOME' => $this->getComposerHomePath()]);
        $path = function_exists('apache_getenv') ? apache_getenv('PATH') : getenv('PATH');
        $path .= ':' . dirname(PHP_BINARY);
        $env = $process->getEnv();
        $env['PATH'] = $path;
        $process->setEnv($env);
        return $process;
      }
      return new Process($command);
      // @codeCoverageIgnore
    }
    catch (ExceptionInterface $e) {
      // @codeCoverageIgnore
      throw new LogicException($e->getMessage(), (int) $e->getCode(), $e);
    }
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
