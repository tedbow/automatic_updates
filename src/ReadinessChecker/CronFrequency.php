<?php

namespace Drupal\automatic_updates\ReadinessChecker;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Cron frequency checker.
 */
class CronFrequency implements ReadinessCheckerInterface {
  use StringTranslationTrait;

  /**
   * Minimum cron threshold is 3 hours.
   */
  const MINIMUM_CRON_INTERVAL = 10800;

  /**
   * The config factory.
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
   * CronFrequency constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The state service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(ConfigFactoryInterface $config_factory, ModuleHandlerInterface $module_handler) {
    $this->configFactory = $config_factory;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public function run() {
    $messages = [];
    if ($this->moduleHandler->moduleExists('automated_cron') && $this->configFactory->get('automated_cron.settings')->get('interval') > $this::MINIMUM_CRON_INTERVAL) {
      $messages[] = $this->t('Cron is not set to run frequently enough. <a href="@configure">Configure it</a> to run at least every 3 hours or disable automated cron and run it via an external scheduling system.', [
        '@configure' => Url::fromRoute('system.cron_settings')->toString(),
      ]);
    }
    return $messages;
  }

}
