<?php

namespace Drupal\Tests\package_manager\Kernel;

use Symfony\Component\Process\ExecutableFinder as SymfonyExecutableFinder;

/**
 * @covers \Drupal\package_manager\ExecutableFinder
 *
 * @group package_manager
 */
class ExecutableFinderTest extends PackageManagerKernelTestBase {

  /**
   * Tests that the executable finder looks for paths in configuration.
   */
  public function testCheckConfigurationForExecutablePath(): void {
    $symfony_executable_finder = new class () extends SymfonyExecutableFinder {

      /**
       * {@inheritdoc}
       */
      public function find($name, $default = NULL, array $extraDirs = []) {
        return '/dev/null';
      }

    };
    $this->container->set('package_manager.symfony_executable_finder', $symfony_executable_finder);

    $this->config('package_manager.settings')
      ->set('executables.composer', '/path/to/composer')
      ->save();

    $executable_finder = $this->container->get('package_manager.executable_finder');
    $this->assertSame('/path/to/composer', $executable_finder->find('composer'));
    $this->assertSame('/dev/null', $executable_finder->find('rsync'));
  }

}
