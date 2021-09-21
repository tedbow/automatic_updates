<?php

namespace Drupal\package_manager;

use Drupal\Core\Config\ConfigFactoryInterface;
use PhpTuf\ComposerStager\Infrastructure\Process\FileCopier\FileCopierFactory as StagerFileCopierFactory;
use PhpTuf\ComposerStager\Infrastructure\Process\FileCopier\FileCopierFactoryInterface;
use PhpTuf\ComposerStager\Infrastructure\Process\FileCopier\FileCopierInterface;
use PhpTuf\ComposerStager\Infrastructure\Process\FileCopier\PhpFileCopierInterface;
use PhpTuf\ComposerStager\Infrastructure\Process\FileCopier\RsyncFileCopierInterface;
use Symfony\Component\Process\ExecutableFinder;

/**
 * A file copier factory which returns file copiers according to configuration.
 */
class FileCopierFactory implements FileCopierFactoryInterface {

  /**
   * The decorated file copier factory.
   *
   * @var \PhpTuf\ComposerStager\Infrastructure\Process\FileCopier\FileCopierFactoryInterface
   */
  protected $decorated;

  /**
   * The PHP file copier service.
   *
   * @var \PhpTuf\ComposerStager\Infrastructure\Process\FileCopier\PhpFileCopierInterface
   */
  protected $phpFileCopier;

  /**
   * The rsync file copier service.
   *
   * @var \PhpTuf\ComposerStager\Infrastructure\Process\FileCopier\RsyncFileCopierInterface
   */
  protected $rsyncFileCopier;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a FileCopierFactory object.
   *
   * @param \Symfony\Component\Process\ExecutableFinder $executable_finder
   *   The Symfony executable finder.
   * @param \PhpTuf\ComposerStager\Infrastructure\Process\FileCopier\PhpFileCopierInterface $php_file_copier
   *   The PHP file copier service.
   * @param \PhpTuf\ComposerStager\Infrastructure\Process\FileCopier\RsyncFileCopierInterface $rsync_file_copier
   *   The rsync file copier service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   */
  public function __construct(ExecutableFinder $executable_finder, PhpFileCopierInterface $php_file_copier, RsyncFileCopierInterface $rsync_file_copier, ConfigFactoryInterface $config_factory) {
    $this->decorated = new StagerFileCopierFactory($executable_finder, $php_file_copier, $rsync_file_copier);
    $this->phpFileCopier = $php_file_copier;
    $this->rsyncFileCopier = $rsync_file_copier;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public function create(): FileCopierInterface {
    $copier = $this->configFactory->get('package_manager.settings')
      ->get('file_copier');

    switch ($copier) {
      case 'rsync':
        return $this->rsyncFileCopier;

      case 'php':
        return $this->phpFileCopier;

      default:
        return $this->decorated->create();
    }
  }

}
