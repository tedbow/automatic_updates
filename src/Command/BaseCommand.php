<?php

namespace Drupal\automatic_updates\Command;

use Drupal\Core\DrupalKernel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Base command class.
 */
class BaseCommand extends Command {

  /**
   * The class loader.
   *
   * @var object
   */
  protected $classLoader;

  /**
   * Constructs a new InstallCommand command.
   *
   * @param object $class_loader
   *   The class loader.
   */
  public function __construct($class_loader) {
    $this->classLoader = $class_loader;
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    parent::configure();
    $this->addOption('script-filename', NULL, InputOption::VALUE_REQUIRED, 'The script filename')
      ->addOption('base-url', NULL, InputOption::VALUE_REQUIRED, 'The base URL, i.e. http://example.com/index.php')
      ->addOption('base-path', NULL, InputOption::VALUE_REQUIRED, 'The base path, i.e. http://example.com');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    $this->bootstrapDrupal($input);
  }

  /**
   * Bootstrap Drupal.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The input.
   */
  protected function bootstrapDrupal(InputInterface $input) {
    $kernel = new DrupalKernel('prod', $this->classLoader);
    $script_filename = $input->getOption('script-filename');
    $base_url = $input->getOption('base-url');
    $base_path = $input->getOption('base-path');
    $server = [
      'SCRIPT_FILENAME' => $script_filename,
      'SCRIPT_NAME' => $base_url,
    ];
    $request = Request::create($base_path, 'GET', [], [], [], $server);
    $kernel->handle($request);
  }

}
