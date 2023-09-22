<?php

declare(strict_types = 1);

namespace Drupal\package_manager_test_api;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\package_manager\FailureMarker;
use Drupal\package_manager\PathLocator;
use Drupal\package_manager\StageBase;
use PhpTuf\ComposerStager\API\Core\BeginnerInterface;
use PhpTuf\ComposerStager\API\Core\CommitterInterface;
use PhpTuf\ComposerStager\API\Core\StagerInterface;
use PhpTuf\ComposerStager\API\Path\Factory\PathFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provides API endpoints to interact with a stage directory in functional test.
 */
class ApiController extends ControllerBase {

  /**
   * The route to redirect to after the stage has been applied.
   *
   * @var string
   */
  protected $finishedRoute = 'package_manager_test_api.finish';

  /**
   * The stage.
   *
   * @var \Drupal\package_manager\StageBase
   */
  protected $stage;

  /**
   * The path locator service.
   *
   * @var \Drupal\package_manager\PathLocator
   */
  private $pathLocator;

  /**
   * Constructs an ApiController object.
   *
   * @param \Drupal\package_manager\StageBase $stage
   *   The stage.
   * @param \Drupal\package_manager\PathLocator $path_locator
   *   The path locator service.
   */
  public function __construct(StageBase $stage, PathLocator $path_locator) {
    $this->stage = $stage;
    $this->pathLocator = $path_locator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $stage = new ControllerStage(
      $container->get(PathLocator::class),
      $container->get(BeginnerInterface::class),
      $container->get(StagerInterface::class),
      $container->get(CommitterInterface::class),
      $container->get('file_system'),
      $container->get('event_dispatcher'),
      $container->get('tempstore.shared'),
      $container->get('datetime.time'),
      $container->get(PathFactoryInterface::class),
      $container->get(FailureMarker::class),
    );
    return new static(
      $stage,
      $container->get(PathLocator::class),
    );
  }

  /**
   * Begins a stage life cycle.
   *
   * Creates a stage directory, requires packages into it, applies changes to
   * the active directory.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request. The runtime and dev dependencies are expected to be in
   *   either the query string or request body, under the 'runtime' and 'dev'
   *   keys, respectively. There may also be a 'files_to_return' key, which
   *   contains an array of file paths, relative to the project root, whose
   *   contents should be returned in the response.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A response that directs to the ::finish() method.
   *
   * @see ::finish()
   */
  public function run(Request $request): RedirectResponse {
    $id = $this->createAndApplyStage($request);
    $redirect_url = Url::fromRoute($this->finishedRoute)
      ->setRouteParameter('id', $id)
      ->setOption('query', [
        'files_to_return' => $request->get('files_to_return', []),
      ])
      ->setAbsolute()
      ->toString();

    return new RedirectResponse($redirect_url);
  }

  /**
   * Performs post-apply tasks and destroys the stage.
   *
   * @param string $id
   *   The stage ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request. There may be a 'files_to_return' key in either the query
   *   string or request body which contains an array of file paths, relative to
   *   the project root, whose contents should be returned in the response.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing an associative array of the contents of the
   *   files listed in the 'files_to_return' request key. The array will be
   *   keyed by path, relative to the project root.
   */
  public function finish(string $id, Request $request): JsonResponse {
    $this->stage->claim($id)->postApply();
    $this->stage->destroy();

    $dir = $this->pathLocator->getProjectRoot();
    $file_contents = [];
    foreach ($request->get('files_to_return', []) as $path) {
      $file_contents[$path] = file_get_contents($dir . '/' . $path);
    }
    return new JsonResponse($file_contents);
  }

  /**
   * Creates a stage, requires packages into it, and applies the changes.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request. The runtime and dev dependencies are expected to be in
   *   either the query string or request body, under the 'runtime' and 'dev'
   *   keys, respectively. There may also be a 'files_to_return' key, which
   *   contains an array of file paths, relative to the project root, whose
   *   contents should be returned in the response.
   *
   * @return string
   *   Unique ID for the stage, which can be used to claim the stage before
   *   performing other operations on it. Calling code should store this ID for
   *   as long as the stage needs to exist.
   */
  protected function createAndApplyStage(Request $request) : string {
    $id = $this->stage->create();
    $this->stage->require(
      $request->get('runtime', []),
      $request->get('dev', [])
    );
    $this->stage->apply();
    return $id;
  }

  /**
   * Returns the information about current PHP server used for build tests.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  public function checkSetup(): Response {
    return new Response(
      'max_execution_time=' . ini_get('max_execution_time') .
      ':set_time_limit-exists=' . (function_exists('set_time_limit') ? 'yes' : 'no')
    );
  }

}

/**
 * Non-abstract version of StageBase.
 *
 * This is needed because we cannot instantiate StageBase as it's abstract, and
 * we also can't use anonymous class because the name of anonymous class is
 * always unique for every request which will create problem while claiming the
 * stage as the stored lock will be different from current lock.
 *
 * @see \Drupal\package_manager\StageBase::claim()
 */
final class ControllerStage extends StageBase {}
