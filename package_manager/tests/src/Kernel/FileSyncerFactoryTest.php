<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\package_manager\Traits\AssertPreconditionsTrait;
use PhpTuf\ComposerStager\API\FileSyncer\Service\FileSyncerInterface;
use PhpTuf\ComposerStager\API\FileSyncer\Service\PhpFileSyncerInterface;
use PhpTuf\ComposerStager\API\FileSyncer\Service\RsyncFileSyncerInterface;

/**
 * @covers \Drupal\package_manager\FileSyncerFactory
 * @group package_manager
 * @internal
 */
class FileSyncerFactoryTest extends KernelTestBase {

  use AssertPreconditionsTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['package_manager', 'update'];

  /**
   * Data provider for testFactory().
   *
   * @return mixed[][]
   *   The test cases.
   */
  public function providerFactory(): array {
    return [
      'rsync file syncer' => ['rsync'],
      'php file syncer' => ['php'],
      'no preference' => [NULL],
    ];
  }

  /**
   * Tests creating a file syncer using our specialized factory class.
   *
   * @param string|null $configured_syncer
   *   The syncer to use, as configured in automatic_updates.settings. Can be
   *   'rsync', 'php', or NULL.
   *
   * @dataProvider providerFactory
   */
  public function testFactory(?string $configured_syncer): void {
    switch ($configured_syncer) {
      case 'rsync':
        $expected_syncer = RsyncFileSyncerInterface::class;
        break;

      case 'php':
        $expected_syncer = PhpFileSyncerInterface::class;
        break;

      default:
        $expected_syncer = FileSyncerInterface::class;
        break;
    }

    $this->config('package_manager.settings')
      ->set('file_syncer', $configured_syncer)
      ->save();

    $this->assertInstanceOf($expected_syncer, $this->container->get(FileSyncerInterface::class));
  }

}
