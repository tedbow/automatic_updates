<?php

namespace Drupal\automatic_updates\Event;

use Drupal\automatic_updates\Updater;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\package_manager\Event\PreCreateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Defines an event subscriber to exclude certain paths from update operations.
 */
class ExcludedPathsSubscriber implements EventSubscriberInterface {

  /**
   * The module list service.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  protected $moduleList;

  /**
   * Constructs an UpdateSubscriber.
   *
   * @param \Drupal\Core\Extension\ModuleExtensionList $module_list
   *   The module list service.
   */
  public function __construct(ModuleExtensionList $module_list) {
    $this->moduleList = $module_list;
  }

  /**
   * Reacts to the beginning of an update process.
   *
   * @param \Drupal\package_manager\Event\PreCreateEvent $event
   *   The event object.
   */
  public function preCreate(PreCreateEvent $event): void {
    // If we are doing an automatic update and this module is a git clone,
    // exclude it.
    if ($event->getStage() instanceof Updater && is_dir(__DIR__ . '/../../.git')) {
      $dir = $this->moduleList->getPath('automatic_updates');
      $event->excludePath($dir);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      PreCreateEvent::class => 'preCreate',
    ];
  }

}
