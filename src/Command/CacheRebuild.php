<?php

namespace Drupal\automatic_updates\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Cache rebuild command.
 */
class CacheRebuild extends BaseCommand {

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    parent::configure();
    $this->setName('cache:rebuild')
      ->setAliases(['cr, rebuild'])
      ->setDescription('Rebuild a Drupal site and clear all its caches.');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    parent::execute($input, $output);
    drupal_flush_all_caches();
    $output->writeln('Cache rebuild complete.');
  }

}
