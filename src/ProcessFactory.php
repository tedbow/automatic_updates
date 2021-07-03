<?php

namespace Drupal\automatic_updates;

use PhpTuf\ComposerStager\Exception\LogicException;
use PhpTuf\ComposerStager\Infrastructure\Process\ProcessFactoryInterface;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * @internal
 */
final class ProcessFactory implements ProcessFactoryInterface
{
  public function create(array $command): Process
  {
    try {
      $executable = $command[0];
      $executable_parts = explode('/', $executable);
      $file = array_pop($executable_parts);
      if (strpos($file, 'composer') === 0) {
        return new Process($command, null, ['COMPOSER_HOME' => '/Users/ted.bowman/sites/wdev/d8_stager/composer_home']);
      }
      return new Process($command);
    } catch (ExceptionInterface $e) { // @codeCoverageIgnore
      throw new LogicException($e->getMessage(), (int) $e->getCode(), $e); // @codeCoverageIgnore
    }
  }
}