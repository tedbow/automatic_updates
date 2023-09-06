<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\package_manager\ExecutableFinder;
use Drupal\package_manager\FileSyncerFactory;
use Drupal\package_manager\ProcessFactory;
use Drupal\package_manager\TranslatableStringFactory;
use Drupal\Tests\package_manager\Traits\AssertPreconditionsTrait;
use PhpTuf\ComposerStager\API\FileSyncer\Factory\FileSyncerFactoryInterface;
use PhpTuf\ComposerStager\API\Finder\Service\ExecutableFinderInterface;
use PhpTuf\ComposerStager\API\Process\Factory\ProcessFactoryInterface;
use PhpTuf\ComposerStager\API\Translation\Factory\TranslatableFactoryInterface;

/**
 * Tests that Package Manager services are wired correctly.
 *
 * @group package_manager
 * @internal
 */
class ServicesTest extends KernelTestBase {

  use AssertPreconditionsTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['package_manager', 'update'];

  /**
   * Tests that Package Manager's public services can be instantiated.
   */
  public function testPackageManagerServices(): void {
    // Ensure that any overridden Composer Stager services were overridden
    // correctly.
    $overrides = [
      ExecutableFinderInterface::class => ExecutableFinder::class,
      FileSyncerFactoryInterface::class => FileSyncerFactory::class,
      ProcessFactoryInterface::class => ProcessFactory::class,
      TranslatableFactoryInterface::class => TranslatableStringFactory::class,
    ];
    foreach ($overrides as $interface => $expected_class) {
      $this->assertInstanceOf($expected_class, $this->container->get($interface));
    }
  }

}
