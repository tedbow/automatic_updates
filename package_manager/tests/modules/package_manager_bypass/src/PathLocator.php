<?php

namespace Drupal\package_manager_bypass;

use Drupal\Core\State\StateInterface;
use Drupal\package_manager\PathLocator as BasePathLocator;

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
   * @param string $app_root
   *   The Drupal application root.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(string $app_root, StateInterface $state) {
    parent::__construct($app_root);
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public function getProjectRoot(): string {
    return $this->state->get(static::class . ' root', parent::getProjectRoot());
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
   * Sets the paths to return.
   *
   * @param string|null $project_root
   *   The project root, or NULL to defer to the parent class.
   * @param string|null $vendor_dir
   *   The vendor directory, or NULL to defer to the parent class.
   * @param string|null $web_root
   *   The web root, relative to the project root, or NULL to defer to the
   *   parent class.
   */
  public function setPaths(?string $project_root, ?string $vendor_dir, ?string $web_root): void {
    $this->state->set(static::class . ' root', $project_root);
    $this->state->set(static::class . ' vendor', $vendor_dir);
    $this->state->set(static::class . ' web', $web_root);
  }

}
