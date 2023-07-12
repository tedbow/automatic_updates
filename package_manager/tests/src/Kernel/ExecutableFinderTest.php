<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\package_manager\ExecutableFinder;
use PhpTuf\ComposerStager\API\Finder\Service\ExecutableFinderInterface;
use PhpTuf\ComposerStager\Internal\Finder\Service\ExecutableFinder as StagerExecutableFinder;
use PhpTuf\ComposerStager\API\Translation\Factory\TranslatableFactoryInterface;
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
    $symfony_executable_finder = new class extends SymfonyExecutableFinder {

      /**
       * {@inheritdoc}
       */
      public function find($name, $default = NULL, array $extraDirs = []): ?string {
        return '/dev/null';
      }

    };
    $container->getDefinition(ExecutableFinder::class)
      ->setArgument('$decorated', new StagerExecutableFinder(
        $symfony_executable_finder,
        $this->createMock(TranslatableFactoryInterface::class),
      ));
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
