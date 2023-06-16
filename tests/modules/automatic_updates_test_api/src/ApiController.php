<?php

declare(strict_types = 1);

namespace Drupal\automatic_updates_test_api;

use Drupal\package_manager\Debugger;
use Drupal\package_manager_test_api\ApiController as PackageManagerApiController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

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

  public function testProcess(): array {
    $path_locator = \Drupal::service('package_manager.path_locator');
    $drush_path = $path_locator->getVendorDirectory() . '/drush/drush/drush';
    $phpBinaryFinder = new PhpExecutableFinder();
    sleep(5);
    $process = Process::fromShellCommandline($phpBinaryFinder->find() . " $drush_path auto-update &");
    // $process = new Process([$phpBinaryFinder->find(), $drush_path, 'auto-update', '&']);
    $process->setWorkingDirectory($path_locator->getProjectRoot() . DIRECTORY_SEPARATOR . $path_locator->getWebRoot());
    $process->disableOutput();
    $process->setTimeout(0);
    try {
      $process->start();
    }
    catch (\Throwable $throwable) {
      // @todo Does this work 10.0.x?
      watchdog_exception('auto_updates', $throwable, 'Could not perform background update.');
      Debugger::debugOutput($process->getErrorOutput(), 'process error');
      Debugger::debugOutput($process->getOutput(), 'process output');
      Debugger::debugOutput($throwable, 'Could not perform background update.');
    }
    return [
      '#type' => 'markup',
      '#markup' => time(),
    ];
  }

}
