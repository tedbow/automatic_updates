<?php

namespace Drupal\automatic_updates_extensions_test_api;

use Drupal\automatic_updates_extensions\ExtensionUpdater;
use Drupal\Core\Controller\ControllerBase;
use Drupal\package_manager\PathLocator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides API endpoints to interact with a staging area in functional tests.
 */
class ApiController extends ControllerBase {


  /**
   * The extension updater.
   *
   * @var \Drupal\automatic_updates_extensions\ExtensionUpdater
   */
  private $extensionUpdater;

  /**
   * The path locator service.
   *
   * @var \Drupal\package_manager\PathLocator
   */
  private $pathLocator;

  /**
   * Constructs an ApiController object.
   *
   * @param \Drupal\automatic_updates_extensions\ExtensionUpdater $extensionUpdater
   *   The updater.
   * @param \Drupal\package_manager\PathLocator $path_locator
   *   The path locator service.
   */
  public function __construct(ExtensionUpdater $extensionUpdater, PathLocator $path_locator) {
    $this->extensionUpdater = $extensionUpdater;
    $this->pathLocator = $path_locator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('automatic_updates_extensions.updater'),
      $container->get('package_manager.path_locator')
    );
  }

  /**
   * Runs a complete stage life cycle.
   *
   * Creates a staging area, requires packages into it, applies changes to the
   * active directory, and destroys the stage.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request. The runtime and dev dependencies are expected to be in
   *   either the query string or request body, under the 'runtime' and 'dev'
   *   keys, respectively. There may also be a 'files_to_return' key, which
   *   contains an array of file paths, relative to the project root, whose
   *   contents should be returned in the response.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing an associative array of the contents of the
   *   files listed in the 'files_to_return' request key. The array will be
   *   keyed by path, relative to the project root.
   */
  public function run(Request $request): JsonResponse {
    $this->extensionUpdater->begin($request->get('projects', []));
    $this->extensionUpdater->stage();
    $this->extensionUpdater->apply();
    $this->extensionUpdater->destroy();

    $dir = $this->pathLocator->getProjectRoot();
    $file_contents = [];
    foreach ($request->get('files_to_return', []) as $path) {
      $file_contents[$path] = file_get_contents($dir . '/' . $path);
    }
    return new JsonResponse($file_contents);
  }

}
