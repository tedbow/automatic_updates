<?php

namespace Drupal\package_manager_test_api;

use Drupal\Core\Controller\ControllerBase;
use Drupal\package_manager\PathLocator;
use Drupal\package_manager\Stage;
use PhpTuf\ComposerStager\Infrastructure\Factory\Path\PathFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides API endpoints to interact with a staging area in functional tests.
 */
class ApiController extends ControllerBase {

  /**
   * The stage.
   *
   * @var \Drupal\package_manager\Stage
   */
  private $stage;

  /**
   * The path locator service.
   *
   * @var \Drupal\package_manager\PathLocator
   */
  private $pathLocator;

  /**
   * Constructs an ApiController object.
   *
   * @param \Drupal\package_manager\Stage $stage
   *   The stage.
   * @param \Drupal\package_manager\PathLocator $path_locator
   *   The path locator service.
   */
  public function __construct(Stage $stage, PathLocator $path_locator) {
    $this->stage = $stage;
    $this->pathLocator = $path_locator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $stage = new Stage(
      $container->get('config.factory'),
      $container->get('package_manager.path_locator'),
      $container->get('package_manager.beginner'),
      $container->get('package_manager.stager'),
      $container->get('package_manager.committer'),
      $container->get('file_system'),
      $container->get('event_dispatcher'),
      $container->get('tempstore.shared'),
      $container->get('datetime.time'),
      $container->get(PathFactoryInterface::class)
    );
    return new static(
      $stage,
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
    $this->stage->create();
    $this->stage->require(
      $request->get('runtime', []),
      $request->get('dev', [])
    );
    $this->stage->apply();
    $this->stage->destroy();

    $dir = $this->pathLocator->getProjectRoot();
    $file_contents = [];
    foreach ($request->get('files_to_return', []) as $path) {
      $file_contents[$path] = file_get_contents($dir . '/' . $path);
    }
    return new JsonResponse($file_contents);
  }

}
