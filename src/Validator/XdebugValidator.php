<?php

namespace Drupal\automatic_updates\Validator;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\automatic_updates\CronUpdater;
use Drupal\automatic_updates\Updater;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;
use Drupal\package_manager\Event\StatusCheckEvent;
use Drupal\package_manager\Validator\XdebugValidator as PackageManagerXdebugValidator;

/**
 * Performs validation if Xdebug is enabled.
 *
 * @internal
 *   This is an internal part of Automatic Updates and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
final class XdebugValidator implements EventSubscriberInterface {

  /**
   * The Package Manager validator we're wrapping.
   *
   * @var \Drupal\package_manager\Validator\XdebugValidator
   */
  private $packageManagerValidator;

  /**
   * Constructs an XdebugValidator object.
   *
   * @param \Drupal\package_manager\Validator\XdebugValidator $package_manager_validator
   *   The Package Manager validator we're wrapping.
   */
  public function __construct(PackageManagerXdebugValidator $package_manager_validator) {
    $this->packageManagerValidator = $package_manager_validator;
  }

  /**
   * Performs validation if Xdebug is enabled.
   *
   * @param \Drupal\package_manager\Event\PreOperationStageEvent $event
   *   The event object.
   */
  public function checkForXdebug(PreOperationStageEvent $event): void {
    $stage = $event->getStage();

    // We only want to do this check if the stage belongs to Automatic Updates.
    if (!($stage instanceof Updater)) {
      return;
    }

    $status_check = new StatusCheckEvent($stage, []);
    $this->packageManagerValidator->checkForXdebug($status_check);
    $results = $status_check->getResults();
    if (empty($results)) {
      return;
    }
    elseif ($stage instanceof CronUpdater) {
      // Cron updates are not allowed if Xdebug is enabled.
      foreach ($results as $result) {
        $event->addError($result->getMessages(), $result->getSummary());
      }
    }
    elseif ($event instanceof StatusCheckEvent) {
      // For non-cron updates provide a warning but do not stop updates from
      // executing.
      foreach ($results as $result) {
        $event->addWarning($result->getMessages(), $result->getSummary());
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PreCreateEvent::class => 'checkForXdebug',
      StatusCheckEvent::class => 'checkForXdebug',
    ];
  }

}
