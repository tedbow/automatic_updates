<?php

namespace Drupal\automatic_updates\Plugin\DatabaseUpdateHandler;

use Drupal\automatic_updates\DatabaseUpdateHandlerPluginBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Ignore database updates.
 *
 * @DatabaseUpdateHandler(
 *   id = "ignore_updates",
 *   label = "Ignore database updates",
 * )
 */
class IgnoreUpdates extends DatabaseUpdateHandlerPluginBase implements ContainerFactoryPluginInterface {

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
    $this->logger->notice('Database updates ignored.');
    // Ignore the updates and hope for the best.
    return TRUE;
  }

}
