<?php

namespace Drupal\package_manager_test_api;

use Drupal\Core\Controller\ControllerBase;
use Drupal\package_manager\Stage;
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
   * Constructs an ApiController object.
   *
   * @param \Drupal\package_manager\Stage $stage
   *   The stage.
   */
  public function __construct(Stage $stage) {
    $this->stage = $stage;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $stage = new Stage(
      $container->get('package_manager.path_locator'),
      $container->get('package_manager.beginner'),
      $container->get('package_manager.stager'),
      $container->get('package_manager.committer'),
      $container->get('file_system'),
      $container->get('event_dispatcher'),
      $container->get('tempstore.shared'),
      $container->get('datetime.time')
    );
    return new static($stage);
  }

  /**
   * Creates a staging area and requires packages into it.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request. The runtime and dev dependencies are expected to be in
   *   either the query string or request body, under the 'runtime' and 'dev'
   *   keys, respectively. There may also be a 'files_to_return' key, which
   *   contains an array of file paths, relative to the stage directory, whose
   *   contents should be returned in the response.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing an associative array of the contents of the
   *   staged files listed in the 'files_to_return' request key. The array will
   *   be keyed by path, relative to the stage directory.
   */
  public function require(Request $request): JsonResponse {
    $this->stage->create();
    $this->stage->require(
      $request->get('runtime', []),
      $request->get('dev', [])
    );

    $stage_dir = $this->stage->getStageDirectory();
    $staged_file_contents = [];
    foreach ($request->get('files_to_return', []) as $path) {
      $staged_file_contents[$path] = file_get_contents($stage_dir . '/' . $path);
    }
    $this->stage->destroy();

    return new JsonResponse($staged_file_contents);
  }

}
