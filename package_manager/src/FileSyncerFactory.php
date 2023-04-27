<?php

declare(strict_types = 1);

namespace Drupal\package_manager;

use Drupal\Core\Config\ConfigFactoryInterface;
use PhpTuf\ComposerStager\Domain\Service\FileSyncer\FileSyncerInterface;
use PhpTuf\ComposerStager\Infrastructure\Factory\FileSyncer\FileSyncerFactory as StagerFileSyncerFactory;
use PhpTuf\ComposerStager\Infrastructure\Service\FileSyncer\PhpFileSyncer;
use PhpTuf\ComposerStager\Infrastructure\Service\FileSyncer\RsyncFileSyncer;
use Symfony\Component\Process\ExecutableFinder;

/**
 * A file syncer factory which creates a file syncer according to configuration.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
final class FileSyncerFactory {

  /**
   * The decorated file syncer factory.
   *
   * @var \PhpTuf\ComposerStager\Infrastructure\Factory\FileSyncer\FileSyncerFactory
   */
  private $decorated;

  /**
   * Constructs a FileCopierFactory object.
   *
   * @param \Symfony\Component\Process\ExecutableFinder $executable_finder
   *   The Symfony executable finder.
   * @param \PhpTuf\ComposerStager\Infrastructure\Service\FileSyncer\PhpFileSyncer $phpFileSyncer
   *   The PHP file syncer service.
   * @param \PhpTuf\ComposerStager\Infrastructure\Service\FileSyncer\RsyncFileSyncer $rsyncFileSyncer
   *   The rsync file syncer service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   */
  public function __construct(
    ExecutableFinder $executable_finder,
    private readonly PhpFileSyncer $phpFileSyncer,
    private readonly RsyncFileSyncer $rsyncFileSyncer,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    $this->decorated = new StagerFileSyncerFactory($executable_finder, $phpFileSyncer, $rsyncFileSyncer);
  }

  /**
   * {@inheritdoc}
   */
  public function create(): FileSyncerInterface {
    $syncer = $this->configFactory->get('package_manager.settings')
      ->get('file_syncer');

    switch ($syncer) {
      case 'rsync':
        return $this->rsyncFileSyncer;

      case 'php':
        return $this->phpFileSyncer;

      default:
        return $this->decorated->create();
    }
  }

}
