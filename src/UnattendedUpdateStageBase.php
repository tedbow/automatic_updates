<?php

declare(strict_types = 1);

namespace Drupal\automatic_updates;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\package_manager\ComposerInspector;
use Drupal\package_manager\FailureMarker;
use Drupal\package_manager\PathLocator;
use Drupal\update\ProjectRelease;
use PhpTuf\ComposerStager\Domain\Core\Beginner\BeginnerInterface;
use PhpTuf\ComposerStager\Domain\Core\Committer\CommitterInterface;
use PhpTuf\ComposerStager\Domain\Core\Stager\StagerInterface;
use PhpTuf\ComposerStager\Infrastructure\Factory\Path\PathFactoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * A base class for unattended updates.
 */
class UnattendedUpdateStageBase extends UpdateStage {

  /**
   * The current interface between PHP and the server.
   *
   * @var string
   */
  protected static $serverApi = PHP_SAPI;

  /**
   * All automatic updates are disabled.
   *
   * @var string
   */
  public const DISABLED = 'disable';

  /**
   * Only perform automatic security updates.
   *
   * @var string
   */
  public const SECURITY = 'security';

  /**
   * All automatic updates are enabled.
   *
   * @var string
   */
  public const ALL = 'patch';

  /**
   * Constructs a UnattendedUpdateStageBase object.
   *
   * @param \Drupal\automatic_updates\ReleaseChooser $releaseChooser
   *   The cron release chooser service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   * @param \Drupal\package_manager\ComposerInspector $composerInspector
   *   The Composer inspector service.
   * @param \Drupal\package_manager\PathLocator $pathLocator
   *   The path locator service.
   * @param \PhpTuf\ComposerStager\Domain\Core\Beginner\BeginnerInterface $beginner
   *   The beginner service.
   * @param \PhpTuf\ComposerStager\Domain\Core\Stager\StagerInterface $stager
   *   The stager service.
   * @param \PhpTuf\ComposerStager\Domain\Core\Committer\CommitterInterface $committer
   *   The committer service.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher service.
   * @param \Drupal\Core\TempStore\SharedTempStoreFactory $tempStoreFactory
   *   The shared tempstore factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \PhpTuf\ComposerStager\Infrastructure\Factory\Path\PathFactoryInterface $pathFactory
   *   The path factory service.
   * @param \Drupal\package_manager\FailureMarker $failureMarker
   *   The failure marker service.
   */
  public function __construct(
    private readonly ReleaseChooser $releaseChooser,
    protected readonly ConfigFactoryInterface $configFactory,
    ComposerInspector $composerInspector,
    PathLocator $pathLocator,
    BeginnerInterface $beginner,
    StagerInterface $stager,
    CommitterInterface $committer,
    FileSystemInterface $fileSystem,
    EventDispatcherInterface $eventDispatcher,
    SharedTempStoreFactory $tempStoreFactory,
    TimeInterface $time,
    PathFactoryInterface $pathFactory,
    FailureMarker $failureMarker,
  ) {
    parent::__construct($composerInspector, $pathLocator, $beginner, $stager, $committer, $fileSystem, $eventDispatcher, $tempStoreFactory, $time, $pathFactory, $failureMarker);
  }

  /**
   * Gets the cron update mode.
   *
   * @return string
   *   The cron update mode. Will be one of the following constants:
   *   - \Drupal\automatic_updates\CronUpdateStage::DISABLED if updates during
   *     cron are entirely disabled.
   *   - \Drupal\automatic_updates\CronUpdateStage::SECURITY only security
   *     updates can be done during cron.
   *   - \Drupal\automatic_updates\CronUpdateStage::ALL if all updates are
   *     allowed during cron.
   */
  final public function getMode(): string {
    $mode = $this->configFactory->get('automatic_updates.settings')->get('unattended.level');
    return $mode ?: static::SECURITY;
  }

  /**
   * Indicates if we are currently running at the command line.
   *
   * @return bool
   *   TRUE if we are running at the command line, otherwise FALSE.
   */
  final public static function isCommandLine(): bool {
    return self::$serverApi === 'cli';
  }

  /**
   * Returns the release of Drupal core to update to, if any.
   *
   * @return \Drupal\update\ProjectRelease|null
   *   The release of Drupal core to which we will update, or NULL if there is
   *   nothing to update to.
   */
  public function getTargetRelease(): ?ProjectRelease {
    return $this->releaseChooser->getLatestInInstalledMinor($this);
  }

}
