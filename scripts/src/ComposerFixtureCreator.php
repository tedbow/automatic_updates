<?php

declare(strict_types = 1);

namespace Drupal\automatic_updates\Development;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Creates the test fixture at 'package_manager/tests/fixtures/fake_site'.
 */
final class ComposerFixtureCreator {

  const FIXTURE_PATH = __DIR__ . '/../../package_manager/tests/fixtures/fake_site';

  /**
   * Creates the fixture.
   */
  public static function createFixture(): void {
    $fs = new Filesystem();
    $fs->remove(static::FIXTURE_PATH . "/composer.lock");
    // Remove all the vendor folders but leave our 2 test files.
    // @see \Drupal\Tests\package_manager\Kernel\PathExcluder\VendorHardeningExcluderTest
    self::removeAllExcept(self::FIXTURE_PATH . "/vendor", ['.htaccess', 'web.config']);

    static::doComposerInstall();
    static::removeAllExcept(static::FIXTURE_PATH . '/vendor/composer', ['installed.json', 'installed.php']);
    $fs->remove(static::FIXTURE_PATH . '/vendor/autoload.php');

    $process = new Process(['composer', 'phpcbf'], __DIR__ . '/../..');
    $process->run();
    print "\nFixture created 🎉.";
  }

  /**
   * Runs a Composer command at the fixture root.
   *
   * @param array $command
   *   The command to run as passed to
   *   \Symfony\Component\Process\Process::__construct.
   *
   * @return string
   *   The Composer command output.
   */
  protected static function runComposerCommand(array $command): string {
    array_unshift($command, 'composer');
    $command[] = "--working-dir=" . static::FIXTURE_PATH;
    $process = new Process($command);
    $process->run();
    if (!$process->isSuccessful()) {
      throw new ProcessFailedException($process);
    }
    return $process->getOutput();
  }

  /**
   * Removes all files in a directory except the ones specified.
   *
   * @param string $directory
   *   The directory path.
   * @param string[] $files_to_keep
   *   The files to not delete.
   */
  protected static function removeAllExcept(string $directory, array $files_to_keep): void {
    if (!is_dir($directory)) {
      throw new \LogicException("Expected directory $directory");
    }
    $paths_to_remove = glob("$directory/*");
    $fs = new Filesystem();
    foreach ($paths_to_remove as $path_to_remove) {
      $base_name = basename($path_to_remove);
      if (!in_array($base_name, $files_to_keep, TRUE)) {
        $fs->remove($path_to_remove);
      }
    }
  }

  /**
   * Runs `composer install`.
   */
  protected static function doComposerInstall(): void {
    // Disable Packagist entirely so that we don't test the Internet.
    static::runComposerCommand(['config', 'repo.packagist.org', 'false']);
    static::runComposerCommand(['install']);
  }

}
