<?php

declare(strict_types = 1);

namespace Drupal\automatic_updates\Validator;

use Drupal\automatic_updates\CronUpdateStage;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\package_manager\Event\StatusCheckEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ensures that updates cannot be triggered by Automated Cron.
 *
 * @internal
 *   This is an internal part of Automatic Updates and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
final class AutomatedCronDisabledValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * Flags whether the KernelEvents::TERMINATE event has been dispatched.
   *
   * @var bool
   */
  private bool $terminateCalled = FALSE;

  /**
   * AutomatedCronDisabledValidator constructor.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   */
  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Checks that Automated Cron is not going to trigger unattended updates.
   *
   * @param \Drupal\package_manager\Event\StatusCheckEvent $event
   *   The event being handled.
   */
  public function validateStatusCheck(StatusCheckEvent $event): void {
    if ($event->stage instanceof CronUpdateStage && $this->moduleHandler->moduleExists('automated_cron')) {
      $event->addWarning([
        $this->t('This site has the Automated Cron module installed. To use unattended automatic updates, configure cron manually on your hosting environment. The Automatic Updates module will not do anything if it is triggered by Automated Cron. See the <a href=":url">Automated Cron documentation</a> for information.', [
          ':url' => 'https://www.drupal.org/docs/administering-a-drupal-site/cron-automated-tasks/cron-automated-tasks-overview#s-more-reliable-enable-cron-using-external-trigger',
        ]),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      StatusCheckEvent::class => 'validateStatusCheck',
      // Ensure this runs before
      // \Drupal\automated_cron\EventSubscriber\AutomatedCron::onTerminate().
      KernelEvents::TERMINATE => ['setTerminateCalled', PHP_INT_MAX],
    ];
  }

  /**
   * Sets a flag is when the kernel terminates.
   */
  public function setTerminateCalled(): void {
    $this->terminateCalled = TRUE;
  }

  /**
   * Determines whether the kernel has terminated.
   *
   * @return bool
   *   TRUE if the kernel has terminated (i.e., KernelEvents::TERMINATE has been
   *   handled), otherwise FALSE.
   */
  public function hasTerminateBeenCalled(): bool {
    return $this->terminateCalled;
  }

}
