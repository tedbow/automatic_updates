<?php

namespace Drupal\automatic_updates\Validator;

use Drupal\automatic_updates\CronUpdater;
use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\package_manager\ValidationResult;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates that cron runs frequently enough to perform automatic updates.
 */
class CronFrequencyValidator implements EventSubscriberInterface {

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
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * CronFrequencyValidator constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $translation
   *   The translation service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, ModuleHandlerInterface $module_handler, StateInterface $state, TimeInterface $time, TranslationInterface $translation) {
    $this->configFactory = $config_factory;
    $this->moduleHandler = $module_handler;
    $this->state = $state;
    $this->time = $time;
    $this->setStringTranslation($translation);
  }

  /**
   * Validates that cron runs frequently enough to perform automatic updates.
   *
   * @param \Drupal\automatic_updates\Event\ReadinessCheckEvent $event
   *   The event object.
   */
  public function checkCronFrequency(ReadinessCheckEvent $event): void {
    $cron_enabled = $this->configFactory->get('automatic_updates.settings')
      ->get('cron');

    // If automatic updates are disabled during cron, there's nothing we need
    // to validate.
    if ($cron_enabled === CronUpdater::DISABLED) {
      return;
    }
    elseif ($this->moduleHandler->moduleExists('automated_cron')) {
      $this->validateAutomatedCron($event);
    }
    else {
      $this->validateLastCronRun($event);
    }
  }

  /**
   * Validates the cron frequency according to Automated Cron settings.
   *
   * @param \Drupal\automatic_updates\Event\ReadinessCheckEvent $event
   *   The event object.
   */
  protected function validateAutomatedCron(ReadinessCheckEvent $event): void {
    $message = $this->t('Cron is not set to run frequently enough. <a href=":configure">Configure it</a> to run at least every @frequency hours or disable automated cron and run it via an external scheduling system.', [
      ':configure' => Url::fromRoute('system.cron_settings')->toString(),
      '@frequency' => static::SUGGESTED_INTERVAL,
    ]);

    $interval = $this->configFactory->get('automated_cron.settings')->get('interval');

    if ($interval > static::ERROR_INTERVAL) {
      $error = ValidationResult::createError([$message]);
      $event->addValidationResult($error);
    }
    elseif ($interval > static::WARNING_INTERVAL) {
      $warning = ValidationResult::createWarning([$message]);
      $event->addValidationResult($warning);
    }
  }

  /**
   * Validates the cron frequency according to the last cron run time.
   *
   * @param \Drupal\automatic_updates\Event\ReadinessCheckEvent $event
   *   The event object.
   */
  protected function validateLastCronRun(ReadinessCheckEvent $event): void {
    // Determine when cron last ran. If not known, use the time that Drupal was
    // installed, defaulting to the beginning of the Unix epoch.
    $cron_last = $this->state->get('system.cron_last', $this->state->get('install_time', 0));

    // @todo Should we allow a little extra time in case the server job takes
    //   longer than expected? Otherwise a server setup with a 3-hour cron job
    //   will always give this warning. Maybe this isn't necessary because the
    //   last cron run time is recorded after cron runs. Address this in
    //   https://www.drupal.org/project/automatic_updates/issues/3248544.
    if ($this->time->getRequestTime() - $cron_last > static::WARNING_INTERVAL) {
      $error = ValidationResult::createError([
        $this->t('Cron has not run recently. For more information, see the online handbook entry for <a href=":cron-handbook">configuring cron jobs</a> to run at least every @frequency hours.', [
          ':cron-handbook' => 'https://www.drupal.org/cron',
          '@frequency' => static::SUGGESTED_INTERVAL,
        ]),
      ]);
      $event->addValidationResult($error);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      ReadinessCheckEvent::class => 'checkCronFrequency',
    ];
  }

}
