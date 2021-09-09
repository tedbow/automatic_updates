<?php

namespace Drupal\automatic_updates;

use Composer\Autoload\ClassLoader;
use Drupal\Component\FileSystem\FileSystem;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Computes file system paths that are needed for automatic updates.
 */
class PathLocator {

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The absolute path of the running Drupal code base.
   *
   * @var string
   */
  protected $appRoot;

  /**
   * Constructs a PathLocator object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param string $app_root
   *   The absolute path of the running Drupal code base.
   */
  public function __construct(ConfigFactoryInterface $config_factory, string $app_root) {
    $this->configFactory = $config_factory;
    $this->appRoot = $app_root;
  }

  /**
   * Returns the path of the active directory, which should be updated.
   *
   * @return string
   *   The absolute path which should be updated.
   */
  public function getActiveDirectory(): string {
    return $this->getProjectRoot();
  }

  /**
   * Returns the path of the directory where updates should be staged.
   *
   * This directory may be made world-writeable for clean-up, so it should be
   * somewhere that doesn't put the Drupal installation at risk.
   *
   * @return string
   *   The absolute path of the directory where updates should be staged.
   *
   * @see \Drupal\automatic_updates\ComposerStager\Cleaner::clean()
   */
  public function getStageDirectory(): string {
    // Append the site ID to the directory in order to support parallel test
    // runs, or multiple sites hosted on the same server.
    $site_id = $this->configFactory->get('system.site')->get('uuid');
    return FileSystem::getOsTemporaryDirectory() . DIRECTORY_SEPARATOR . '.automatic_updates_stage_' . $site_id;
  }

  /**
   * Returns the absolute path of the project root.
   *
   * This is where the project-level composer.json should normally be found, and
   * may or may not be the same path as the Drupal code base.
   *
   * @return string
   *   The absolute path of the project root.
   */
  public function getProjectRoot(): string {
    // Assume that the vendor directory is immediately below the project root.
    return realpath($this->getVendorDirectory() . DIRECTORY_SEPARATOR . '..');
  }

  /**
   * Returns the absolute path of the vendor directory.
   *
   * @return string
   *   The absolute path of the vendor directory.
   */
  public function getVendorDirectory(): string {
    $reflector = new \ReflectionClass(ClassLoader::class);
    return dirname($reflector->getFileName(), 2);
  }

  /**
   * Returns the path of the Drupal installation, relative to the project root.
   *
   * @return string
   *   The path of the Drupal installation, relative to the project root and
   *   without leading or trailing slashes. Will return an empty string if the
   *   project root and Drupal root are the same.
   */
  public function getWebRoot(): string {
    $web_root = str_replace($this->getProjectRoot(), NULL, $this->appRoot);
    return trim($web_root, DIRECTORY_SEPARATOR);
  }

}
