<?php

declare(strict_types = 1);

namespace Drupal\package_manager;

use Drupal\Core\Config\ConfigFactoryInterface;
use PhpTuf\ComposerStager\API\FileSyncer\Factory\FileSyncerFactoryInterface;
use PhpTuf\ComposerStager\API\FileSyncer\Service\FileSyncerInterface;
use PhpTuf\ComposerStager\API\FileSyncer\Service\PhpFileSyncerInterface;
use PhpTuf\ComposerStager\API\FileSyncer\Service\RsyncFileSyncerInterface;

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
   * Constructs a FileSyncerFactory object.
   *
   * @param \PhpTuf\ComposerStager\API\FileSyncer\Factory\FileSyncerFactoryInterface $decorated
   *   The decorated file syncer factory.
   * @param \PhpTuf\ComposerStager\API\FileSyncer\Service\PhpFileSyncerInterface $phpFileSyncer
   *   The PHP file syncer service.
   * @param \PhpTuf\ComposerStager\API\FileSyncer\Service\RsyncFileSyncerInterface $rsyncFileSyncer
   *   The rsync file syncer service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   */
  public function __construct(
    private readonly FileSyncerFactoryInterface $decorated,
    private readonly PhpFileSyncerInterface $phpFileSyncer,
    private readonly RsyncFileSyncerInterface $rsyncFileSyncer,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

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
