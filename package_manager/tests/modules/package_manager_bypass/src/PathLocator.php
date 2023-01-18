<?php

declare(strict_types = 1);

namespace Drupal\package_manager_bypass;

use Drupal\Core\State\StateInterface;
use Drupal\package_manager\PathLocator as BasePathLocator;
use Drupal\package_manager\Path;

/**
 * Overrides the path locator to return pre-set values for testing purposes.
 */
class PathLocator extends BasePathLocator {

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  private $state;

  /**
   * Constructs a PathLocator object.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param mixed ...$arguments
   *   Additional arguments to pass to the parent constructor.
   */
  public function __construct(StateInterface $state, ...$arguments) {
    parent::__construct(...$arguments);
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public function getProjectRoot(): string {
    $project_root = $this->state->get(static::class . ' root');
    if ($project_root === NULL) {
      $project_root = $this->getVendorDirectory() . DIRECTORY_SEPARATOR . '..';
      // @see https://github.com/bovigo/vfsStream/issues/207
      $project_root = !str_starts_with($project_root, 'vfs://')
        ? realpath($project_root)
        : $project_root;
    }
    return $project_root;
  }

  /**
   * {@inheritdoc}
   */
  public function getVendorDirectory(): string {
    return $this->state->get(static::class . ' vendor', parent::getVendorDirectory());
  }

  /**
   * {@inheritdoc}
   */
  public function getWebRoot(): string {
    return $this->state->get(static::class . ' web', parent::getWebRoot());
  }

  /**
   * {@inheritdoc}
   */
  public function getStagingRoot(): string {
    return $this->state->get(static::class . ' stage', parent::getStagingRoot());
  }

  /**
   * Sets the paths to return.
   *
   * @param string|null $project_root
   *   The project root, or NULL to defer to the parent class.
   * @param string|null $vendor_dir
   *   The vendor directory, or NULL to defer to the parent class.
   * @param string|null $web_root
   *   The web root, relative to the project root, or NULL to defer to the
   *   parent class.
   * @param string|null $staging_root
   *   The absolute path of the stage root directory, or NULL to defer to the
   *   parent class.
   */
  public function setPaths(?string $project_root, ?string $vendor_dir, ?string $web_root, ?string $staging_root): void {
    foreach ([$project_root, $staging_root] as $path) {
      // @todo Remove the VFS check in https://www.drupal.org/i/3328234.
      if (!empty($path) && !str_starts_with($path, 'vfs://') && !str_starts_with($path, 'sites/') && !Path::isAbsolute($path)) {
        throw new \InvalidArgumentException('project_root and staging_root need to be absolute paths.');
      }
    }
    $this->state->set(static::class . ' root', is_null($project_root) ? NULL : Path::canonicalize($project_root));
    $this->state->set(static::class . ' vendor', is_null($vendor_dir) ? NULL : Path::canonicalize($vendor_dir));
    $this->state->set(static::class . ' web', is_null($web_root) ? NULL : Path::canonicalize($web_root));
    $this->state->set(static::class . ' stage', is_null($staging_root) ? NULL : Path::canonicalize($staging_root));
  }

}
