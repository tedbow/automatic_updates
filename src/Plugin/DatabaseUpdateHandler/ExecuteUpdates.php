<?php

namespace Drupal\automatic_updates\Plugin\DatabaseUpdateHandler;

use Drupal\automatic_updates\DatabaseUpdateHandlerPluginBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Execute database updates.
 *
 * @DatabaseUpdateHandler(
 *   id = "execute_updates",
 *   label = "Execute database updates",
 * )
 */
class ExecuteUpdates extends DatabaseUpdateHandlerPluginBase implements ContainerFactoryPluginInterface {

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
    $process = automatic_updates_console_command('updatedb');
    if ($errors = $process->getErrorOutput()) {
      $this->logger->error($errors);
      return FALSE;
    }
    return TRUE;
  }

}
