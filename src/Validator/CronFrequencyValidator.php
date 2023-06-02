<?php

declare(strict_types = 1);

namespace Drupal\automatic_updates\Validator;

use Drupal\automatic_updates\CronUpdateStage;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\package_manager\Event\StatusCheckEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates that cron runs frequently enough to perform automatic updates.
 *
 * @internal
 *   This is an internal part of Automatic Updates and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
final class CronFrequencyValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The error-level interval between cron runs, in seconds.
   *
   * If cron runs less frequently than this, an error will be raised during
   * validation. Defaults to 24 hours.
   *
   * @var int
   */
  protected const ERROR_INTERVAL = 86400;

  /**
   * The warning-level interval between cron runs, in seconds.
   *
   * If cron runs less frequently than this, a warning will be raised during
   * validation. Defaults to 3 hours.
   *
   * @var int
   */
  protected const WARNING_INTERVAL = 10800;

  /**
   * The cron frequency, in hours, to suggest in errors or warnings.
   *
   * @var int
   */
  protected const SUGGESTED_INTERVAL = self::WARNING_INTERVAL / 3600;

  /**
   * CronFrequencyValidator constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock service.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly LockBackendInterface $lock,
  ) {}

  /**
   * Validates the cron frequency according to the last cron run time.
   *
   * @param \Drupal\package_manager\Event\StatusCheckEvent $event
   *   The event object.
   */
  public function validateLastCronRun(StatusCheckEvent $event): void {
    // We only want to do this check if the stage belongs to Automatic Updates.
    if (!$event->stage instanceof CronUpdateStage) {
      return;
    }
    // If automatic updates are disabled during cron, there's nothing we need
    // to validate.
    if ($event->stage->getMode() === CronUpdateStage::DISABLED) {
      return;
    }
    // If cron is running right now, cron is clearly being run recently enough!
    if (!$this->lock->lockMayBeAvailable('cron')) {
      return;
    }

    // Determine when cron last ran. If not known, use the time that Drupal was
    // installed, defaulting to the beginning of the Unix epoch.
    $cron_last = $this->state->get('system.cron_last', $this->state->get('install_time', 0));
    if ($this->time->getRequestTime() - $cron_last > static::WARNING_INTERVAL) {
      $event->addError([
        $this->t('Cron has not run recently. For more information, see the online handbook entry for <a href=":cron-handbook">configuring cron jobs</a> to run at least every @frequency hours.', [
          ':cron-handbook' => 'https://www.drupal.org/cron',
          '@frequency' => static::SUGGESTED_INTERVAL,
        ]),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      StatusCheckEvent::class => 'validateLastCronRun',
    ];
  }

}
