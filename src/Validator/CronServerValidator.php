<?php

namespace Drupal\automatic_updates\Validator;

use Drupal\automatic_updates\CronUpdater;
use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Http\RequestStack;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Validates that the current server configuration can run cron updates.
 *
 * @internal
 *   This is an internal part of Automatic Updates and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
final class CronServerValidator implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The type of interface between the web server and the PHP runtime.
   *
   * @var string
   *
   * @see php_sapi_name()
   * @see https://www.php.net/manual/en/reserved.constants.php
   */
  protected static $serverApi = PHP_SAPI;

  /**
   * Constructs a CronServerValidator object.
   *
   * @param \Drupal\Core\Http\RequestStack $request_stack
   *   The request stack service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   */
  public function __construct(RequestStack $request_stack, ConfigFactoryInterface $config_factory) {
    $this->request = $request_stack->getCurrentRequest();
    $this->configFactory = $config_factory;
  }

  /**
   * Checks that the server is configured correctly to run cron updates.
   *
   * @param \Drupal\package_manager\Event\PreOperationStageEvent $event
   *   The event object.
   */
  public function checkServer(PreOperationStageEvent $event): void {
    if (!$event->getStage() instanceof CronUpdater) {
      return;
    }

    $current_port = (int) $this->request->getPort();

    $alternate_port = $this->configFactory->get('automatic_updates.settings')
      ->get('cron_port');
    // If no alternate port is configured, it's the same as the current port.
    $alternate_port = intval($alternate_port) ?: $current_port;

    if (static::$serverApi === 'cli-server' && $current_port === $alternate_port) {
      // @todo Explain how to fix this problem on our help page, and link to it,
      //   in https://drupal.org/i/3312669.
      $event->addError([
        $this->t('Your site appears to be running on the built-in PHP web server on port @port. Drupal cannot be automatically updated with this configuration unless the site can also be reached on an alternate port.', [
          '@port' => $current_port,
        ]),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      ReadinessCheckEvent::class => 'checkServer',
      PreCreateEvent::class => 'checkServer',
    ];
  }

}
