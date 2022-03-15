<?php

namespace Drupal\package_manager_test_api;

use Drupal\Core\State\StateInterface;
use Drupal\package_manager\Event\PostApplyEvent;
use Drupal\package_manager\Event\PostDestroyEvent;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\StageEvent;
use Drupal\package_manager\PathLocator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Defines a service for checking system changes during an update.
 */
class SystemChangeRecorder implements EventSubscriberInterface {

  /**
   * The path locator service.
   *
   * @var \Drupal\package_manager\PathLocator
   */
  private $pathLocator;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  private $state;

  /**
   * Constructs a SystemChangeRecorder object.
   *
   * @param \Drupal\package_manager\PathLocator $path_locator
   *   The path locator service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(PathLocator $path_locator, StateInterface $state) {
    $this->pathLocator = $path_locator;
    $this->state = $state;
  }

  /**
   * Records aspects of system state at various points during an update.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The stage event.
   */
  public function recordSystemState(StageEvent $event): void {
    $results = [];

    // Call a function in a loaded file to ensure it doesn't get reloaded after
    // changes are applied.
    $results['return value of existing global function'] = _updated_module_global1();

    // Check if a new global function exists after changes are applied.
    $results['new global function exists'] = function_exists('_update_module_global2') ? "exists" : "not exists";

    $phase = $event instanceof PreApplyEvent ? 'pre' : 'post';
    $this->state->set("system_changes:$phase", $results);
  }

  /**
   * Writes the results of ::recordSystemState() to file.
   *
   * Build tests do not have access to the Drupal API, so write the results to
   * a file so the build test can check them.
   */
  public function writeResultsToFile(): void {
    $results = [
      'pre' => $this->state->get('system_changes:pre'),
      'post' => $this->state->get('system_changes:post'),
    ];
    $dir = $this->pathLocator->getProjectRoot();
    file_put_contents("$dir/system_changes.json", json_encode($results));
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      PreApplyEvent::class => 'recordSystemState',
      PostApplyEvent::class => 'recordSystemState',
      PostDestroyEvent::class => 'writeResultsToFile',
    ];
  }

}
