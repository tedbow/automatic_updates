<?php

namespace Drupal\automatic_updates\Plugin\DatabaseUpdateHandler;

use Drupal\automatic_updates\DatabaseUpdateHandlerPluginBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Put site into maintenance mode if there are database updates.
 *
 * @DatabaseUpdateHandler(
 *   id = "maintenance_mode_activate",
 *   label = "Put site into maintenance mode",
 * )
 */
class MaintenanceModeActivate extends DatabaseUpdateHandlerPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The state.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a new maintenance mode service.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, StateInterface $state, LoggerInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->state = $state;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('state'),
      $container->get('logger.channel.automatic_updates')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $this->logger->notice('Maintenance mode activated.');
    $this->state->set('system.maintenance_mode', TRUE);
    return TRUE;
  }

}
