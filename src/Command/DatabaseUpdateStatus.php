<?php

namespace Drupal\automatic_updates\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Database update status command.
 */
class DatabaseUpdateStatus extends BaseCommand {

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    parent::configure();
    $this->setName('updatedb-status')
      ->setDescription('List any pending database updates.')
      ->setAliases(['updbst', 'updatedb:status']);
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    parent::execute($input, $output);
    $pending_updates = \Drupal::service('automatic_updates.pending_db_updates')
      ->run();
    $output->writeln($pending_updates);
    return 0;
  }

}
