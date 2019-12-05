<?php

namespace Drupal\automatic_updates\Plugin\DatabaseUpdateHandler;

use Drupal\automatic_updates\DatabaseUpdateHandlerPluginBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Rollback database updates.
 *
 * @DatabaseUpdateHandler(
 *   id = "rollback",
 *   label = "Rollback database updates",
 * )
 */
class RollbackUpdate extends DatabaseUpdateHandlerPluginBase implements ContainerFactoryPluginInterface {

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
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
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
      $container->get('logger.channel.automatic_updates')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $this->logger->notice('Rollback initiated due to database updates.');
    // Simply rollback the update by returning FALSE.
    return FALSE;
  }

}
