<?php

namespace Drupal\automatic_updates;

use PhpTuf\ComposerStager\Exception\LogicException;
use PhpTuf\ComposerStager\Infrastructure\Process\ProcessFactoryInterface;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * Implementation to allow setting
 */
final class ProcessFactory implements ProcessFactoryInterface
{
  public function create(array $command): Process
  {
    try {
      if ($this->isComposerCommand($command)) {
        return new Process($command, null, ['COMPOSER_HOME' => $this->getComposerHomePath()]);
      }
      return new Process($command);
    } catch (ExceptionInterface $e) { // @codeCoverageIgnore
      throw new LogicException($e->getMessage(), (int) $e->getCode(), $e); // @codeCoverageIgnore
    }
  }

  private function getComposerHomePath(): string {
    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = \Drupal::service('file_system');
    $home_path = $file_system->getTempDirectory() . '/automatic_updates_composer_home';
    if (!is_dir($home_path)) {
      mkdir($home_path);
    }
    return $home_path;
  }
  private function isComposerCommand(array $command): bool {
    $executable = $command[0];
    $executable_parts = explode('/', $executable);
    $file = array_pop($executable_parts);
    return strpos($file, 'composer') === 0;
  }
}