<?php

namespace Drupal\composer_stager_bypass;

use PhpTuf\ComposerStager\Domain\CommitterInterface;
use PhpTuf\ComposerStager\Domain\Output\ProcessOutputCallbackInterface;

/**
 * Defines an update committer which doesn't do any actual committing.
 */
class Committer implements CommitterInterface {

  /**
   * The decorated committer service.
   *
   * @var \PhpTuf\ComposerStager\Domain\CommitterInterface
   */
  private $decorated;

  /**
   * Constructs a Committer object.
   *
   * @param \PhpTuf\ComposerStager\Domain\CommitterInterface $decorated
   *   The decorated committer service.
   */
  public function __construct(CommitterInterface $decorated) {
    $this->decorated = $decorated;
  }

  /**
   * {@inheritdoc}
   */
  public function commit(string $stagingDir, string $activeDir, ?ProcessOutputCallbackInterface $callback = NULL, ?int $timeout = 120): void {
  }

  /**
   * {@inheritdoc}
   */
  public function directoryExists(string $stagingDir): bool {
    return $this->decorated->directoryExists($stagingDir);
  }

}
