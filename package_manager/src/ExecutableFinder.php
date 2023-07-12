<?php

declare(strict_types = 1);

namespace Drupal\package_manager;

use Drupal\Core\Config\ConfigFactoryInterface;
use PhpTuf\ComposerStager\API\Finder\Service\ExecutableFinderInterface;
use PhpTuf\ComposerStager\Internal\Finder\Service\ExecutableFinder as StagerExecutableFinder;

/**
 * An executable finder which looks for executable paths in configuration.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
final class ExecutableFinder implements ExecutableFinderInterface {

  /**
   * Constructs an ExecutableFinder object.
   *
   * @param \PhpTuf\ComposerStager\Internal\Finder\Service\ExecutableFinder $decorated
   *   The decorated executable finder.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   */
  public function __construct(
    private readonly StagerExecutableFinder $decorated,
    private readonly ConfigFactoryInterface $configFactory
  ) {}

  /**
   * {@inheritdoc}
   */
  public function find(string $name): string {
    $executables = $this->configFactory->get('package_manager.settings')
      ->get('executables');

    return $executables[$name] ?? $this->decorated->find($name);
  }

}
