<?php

namespace Drupal\automatic_updates\Validator;

use Drupal\automatic_updates\CronUpdater;
use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\automatic_updates\ProjectInfo;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class UnsupportedBranchValidator implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      ReadinessCheckEvent::class => 'checkUpdateVersion',
    ];
  }

  public function checkUpdateVersion(ReadinessCheckEvent $event): void {
    $stage = $event;
    if (!$stage instanceof CronUpdater || $stage->getMode() !== CronUpdater::DISABLED) {
      return;
    }
    $project_info = new ProjectInfo('drupal');
    $project_data = $project_info->getProjectInfo();

  }

}
