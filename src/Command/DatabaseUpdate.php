<?php

namespace Drupal\automatic_updates\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Database update command.
 */
class DatabaseUpdate extends BaseCommand {

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    parent::configure();
    $this->setName('updatedb')
      ->setDescription('Apply any database updates required (as with running update.php).')
      ->setAliases(['updb']);
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    parent::execute($input, $output);
    $pending_updates = \Drupal::service('automatic_updates.pending_db_updates')
      ->run();
    if ($pending_updates) {
      $output->writeln('Started database updates.');
      $this->executeDatabaseUpdates();
      $output->writeln('Finished database updates.');
    }
    else {
      $output->writeln('No database updates required.');
    }
    return 0;
  }

  /**
   * Execute all outstanding database updates.
   */
  protected function executeDatabaseUpdates() {
    require_once DRUPAL_ROOT . '/core/includes/install.inc';
    require_once DRUPAL_ROOT . '/core/includes/update.inc';
    $logger = \Drupal::logger('automatic_updates');
    drupal_load_updates();
    $start = $dependency_map = $operations = [];
    foreach (update_get_update_list() as $module => $update) {
      $start[$module] = $update['start'];
    }
    $updates = update_resolve_dependencies($start);
    foreach ($updates as $function => $update) {
      $dependency_map[$function] = !empty($update['reverse_paths']) ? array_keys($update['reverse_paths']) : [];
    }
    foreach ($updates as $function => $update) {
      if ($update['allowed']) {
        // Set the installed version of each module so updates will start at the
        // correct place. (The updates are already sorted, so we can simply base
        // this on the first one we come across in the above foreach loop.)
        if (isset($start[$update['module']])) {
          drupal_set_installed_schema_version($update['module'], $update['number'] - 1);
          unset($start[$update['module']]);
        }
        $this->executeDatabaseUpdate('update_do_one', [
          $update['module'],
          $update['number'],
          $dependency_map[$function],
        ]);
      }
    }

    $post_updates = \Drupal::service('update.post_update_registry')->getPendingUpdateFunctions();
    if ($post_updates) {
      // Now we rebuild all caches and after that execute hook_post_update().
      $logger->info('Starting cache clear pre-step of database update.');
      automatic_updates_console_command('cache:rebuild');
      $logger->info('Finished cache clear pre-step of database update.');
      foreach ($post_updates as $function) {
        $this->executeDatabaseUpdate('update_invoke_post_update', [$function]);
      }
    }
  }

  /**
   * Execute a single database update.
   *
   * @param callable $invoker
   *   Callable update invoker.
   * @param array $args
   *   The arguments to pass to the invoker.
   */
  protected function executeDatabaseUpdate(callable $invoker, array $args) {
    \Drupal::logger('automatic_updates')->notice('Database update running with arguments "@arguments"', ['@arguments' => print_r($args, TRUE)]);
    $context = [
      'sandbox'  => [],
    ];
    call_user_func_array($invoker, array_merge($args, [&$context]));
    \Drupal::logger('automatic_updates')->notice('Database update finished with arguments "@arguments"', ['@arguments' => print_r($args, TRUE)]);
  }

}
