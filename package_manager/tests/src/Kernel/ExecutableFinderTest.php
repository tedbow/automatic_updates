<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\package_manager\ExecutableFinder;
use PhpTuf\ComposerStager\API\Finder\Service\ExecutableFinderInterface;
use Symfony\Component\Process\ExecutableFinder as SymfonyExecutableFinder;

/**
 * @covers \Drupal\package_manager\ExecutableFinder
 * @group package_manager
 * @internal
 */
class ExecutableFinderTest extends PackageManagerKernelTestBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    // Mock a Symfony executable finder that always returns /dev/null.
    $container->set(SymfonyExecutableFinder::class, new class extends SymfonyExecutableFinder {

      /**
       * {@inheritdoc}
       */
      public function find($name, $default = NULL, array $extraDirs = []): ?string {
        return '/dev/null';
      }

    });
  }

  /**
   * Tests that the executable finder looks for paths in configuration.
   */
  public function testCheckConfigurationForExecutablePath(): void {
    $this->config('package_manager.settings')
      ->set('executables.composer', '/path/to/composer')
      ->save();

    $executable_finder = $this->container->get(ExecutableFinderInterface::class);
    $this->assertInstanceOf(ExecutableFinder::class, $executable_finder);
    $this->assertSame('/path/to/composer', $executable_finder->find('composer'));
    $this->assertSame('/dev/null', $executable_finder->find('rsync'));
  }

}
