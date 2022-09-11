<?php

namespace Drupal\package_manager_bypass;

use Drupal\Core\State\StateInterface;
use PhpTuf\ComposerStager\Domain\Value\Path\PathInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Records information about method invocations.
 *
 * This can be used by functional tests to ensure that the bypassed Composer
 * Stager services were called as expected. Kernel and unit tests should use
 * regular mocks instead.
 */
abstract class BypassedStagerServiceBase {

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The Symfony file system service.
   *
   * @var \Symfony\Component\Filesystem\Filesystem
   */
  protected $fileSystem;

  /**
   * Constructs an InvocationRecorderBase object.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Symfony\Component\Filesystem\Filesystem $file_system
   *   The Symfony file system service.
   */
  public function __construct(StateInterface $state, Filesystem $file_system) {
    $this->state = $state;
    $this->fileSystem = $file_system;
  }

  /**
   * Sets a path to be mirrored into a destination by the main class method.
   *
   * @param string|null $path
   *   A path to mirror into a destination directory when the main class method
   *   is called, or NULL to disable.
   *
   * @see ::copyFixtureFilesTo()
   */
  public static function setFixturePath(?string $path): void {
    \Drupal::state()->set(static::class . ' fixture', $path);
  }

  /**
   * If a fixture path has been set, mirrors it to the given path.
   *
   * Files in the destination directory but not in the source directory will
   * not be deleted.
   *
   * @param \PhpTuf\ComposerStager\Domain\Value\Path\PathInterface $destination
   *   The path to which the fixture files should be mirrored.
   */
  protected function copyFixtureFilesTo(PathInterface $destination): void {
    $fixture_path = $this->state->get(static::class . ' fixture');

    if ($fixture_path && is_dir($fixture_path)) {
      $this->fileSystem->mirror($fixture_path, $destination->resolve(), NULL, [
        'override' => TRUE,
        'delete' => FALSE,
      ]);
    }
  }

  /**
   * Returns the arguments from every invocation of the main class method.
   *
   * @return mixed[]
   *   The arguments from every invocation of the main class method.
   */
  public function getInvocationArguments(): array {
    return $this->state->get(static::class . ' arguments', []);
  }

  /**
   * Records the arguments from an invocation of the main class method.
   *
   * @param mixed ...$arguments
   *   The arguments that the main class method was called with.
   */
  protected function saveInvocationArguments(...$arguments): void {
    $invocations = $this->getInvocationArguments();
    $invocations[] = $arguments;
    $this->state->set(static::class . ' arguments', $invocations);
  }

}
