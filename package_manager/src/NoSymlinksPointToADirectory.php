<?php

declare(strict_types = 1);

namespace Drupal\package_manager;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use PhpTuf\ComposerStager\Domain\Service\Precondition\NoSymlinksPointToADirectoryInterface;
use PhpTuf\ComposerStager\Domain\Value\Path\PathInterface;
use PhpTuf\ComposerStager\Domain\Value\PathList\PathListInterface;

/**
 * Checks if the code base contains any symlinks that point to a directory.
 *
 * Since rsync supports copying symlinks to directories, but Composer Stager's
 * PHP file syncer doesn't, this precondition is automatically fulfilled if
 * Package Manager is *explicitly* configured to use rsync.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
final class NoSymlinksPointToADirectory implements NoSymlinksPointToADirectoryInterface {

  use StringTranslationTrait;

  /**
   * Constructs a NoSymlinksPointToADirectory object.
   *
   * @param \PhpTuf\ComposerStager\Domain\Service\Precondition\NoSymlinksPointToADirectoryInterface $decorated
   *   The decorated precondition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    private readonly NoSymlinksPointToADirectoryInterface $decorated,
    private readonly ConfigFactoryInterface $configFactory
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return $this->decorated->getName();
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return $this->decorated->getDescription();
  }

  /**
   * {@inheritdoc}
   */
  public function getStatusMessage(PathInterface $activeDir, PathInterface $stagingDir, ?PathListInterface $exclusions = NULL,): string {
    if ($this->isUsingRsync()) {
      return $this->t('Symlinks to directories are supported by the rsync file syncer.');
    }
    return $this->decorated->getStatusMessage($activeDir, $stagingDir, $exclusions);
  }

  /**
   * {@inheritdoc}
   */
  public function isFulfilled(PathInterface $activeDir, PathInterface $stagingDir, ?PathListInterface $exclusions = NULL,): bool {
    return $this->isUsingRsync() || $this->decorated->isFulfilled($activeDir, $stagingDir, $exclusions);
  }

  /**
   * {@inheritdoc}
   */
  public function assertIsFulfilled(PathInterface $activeDir, PathInterface $stagingDir, ?PathListInterface $exclusions = NULL,): void {
    if ($this->isUsingRsync()) {
      return;
    }
    $this->decorated->assertIsFulfilled($activeDir, $stagingDir, $exclusions);
  }

  /**
   * Indicates if Package Manager is explicitly configured to use rsync.
   *
   * @return bool
   *   TRUE if Package Manager is explicitly configured to use the rsync file
   *   syncer, FALSE otherwise.
   */
  private function isUsingRsync(): bool {
    $syncer = $this->configFactory->get('package_manager.settings')
      ->get('file_syncer');

    return $syncer === 'rsync';
  }

}
