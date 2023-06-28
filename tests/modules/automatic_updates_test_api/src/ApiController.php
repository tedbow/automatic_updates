<?php

declare(strict_types = 1);

namespace Drupal\automatic_updates_test_api;

use Drupal\package_manager_test_api\ApiController as PackageManagerApiController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiController extends PackageManagerApiController {

  /**
   * {@inheritdoc}
   */
  protected $finishedRoute = 'automatic_updates_test_api.finish';

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('automatic_updates.update_stage'),
      $container->get('package_manager.path_locator')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function createAndApplyStage(Request $request): string {
    $id = $this->stage->begin($request->get('projects', []));
    $this->stage->stage();
    $this->stage->apply();
    return $id;
  }

  /**
   * Resets cron.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  public function resetCron(): Response {
    \Drupal::state()->delete('system.cron_last');
    return new Response('cron reset');
  }

}
