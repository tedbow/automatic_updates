<?php

declare(strict_types = 1);

namespace Drupal\Tests\automatic_updates\Kernel;

use Drupal\automatic_updates\CronUpdateStage;
use Drupal\automatic_updates\ConsoleUpdateStage;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\automatic_updates\Traits\ValidationTestTrait;
use Drupal\Tests\package_manager\Kernel\PackageManagerKernelTestBase;

/**
 * Base class for kernel tests of the Automatic Updates module.
 *
 * @internal
 */
abstract class AutomaticUpdatesKernelTestBase extends PackageManagerKernelTestBase {

  use ValidationTestTrait;

  /**
   * {@inheritdoc}
   *
   * TRICKY: due to the way that automatic_updates forcibly disables cron-based
   * updating for the end user, we need to override the current default
   * configuration BEFORE the module is installed. This triggers config schema
   * exceptions. Since none of these tests are interacting with configuration
   * anyway, this is a reasonable temporary workaround.
   *
   * @see ::setUp()
   * @see https://www.drupal.org/project/automatic_updates/issues/3284443
   * @todo Remove in https://www.drupal.org/project/automatic_updates/issues/3284443
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // If Package Manager's file system permissions validator is disabled, also
    // disable the Automatic Updates validator which wraps it.
    if (in_array('package_manager.validator.file_system', $this->disableValidators, TRUE)) {
      $this->disableValidators[] = 'automatic_updates.validator.file_system_permissions';
    }
    // If Package Manager's symlink validator is disabled, also disable the
    // Automatic Updates validator which wraps it.
    if (in_array('package_manager.validator.symlink', $this->disableValidators, TRUE)) {
      $this->disableValidators[] = 'automatic_updates.validator.symlink';
    }
    parent::setUp();
    // Enable cron updates, which will eventually be the default.
    // @todo Remove in https://www.drupal.org/project/automatic_updates/issues/3284443
    $this->config('automatic_updates.settings')
      ->set('unattended', [
        'method' => 'web',
        'level' => CronUpdateStage::SECURITY,
      ])
      ->save();

    // By default, pretend we're running Drupal core 9.8.0 and a non-security
    // update to 9.8.1 is available.
    $this->setCoreVersion('9.8.0');
    $this->setReleaseMetadata(['drupal' => __DIR__ . '/../../../package_manager/tests/fixtures/release-history/drupal.9.8.1-security.xml']);

    // Set a last cron run time so that the cron frequency validator will run
    // from a sane state.
    // @see \Drupal\automatic_updates\Validator\CronFrequencyValidator
    $this->container->get('state')->set('system.cron_last', time());

    // Cron updates are not done when running at the command line, so override
    // our cron handler's PHP_SAPI constant to a valid value that isn't `cli`.
    // The choice of `cgi-fcgi` is arbitrary; see
    // https://www.php.net/php_sapi_name for some valid values of PHP_SAPI.
    $property = new \ReflectionProperty(CronUpdateStage::class, 'serverApi');
    $property->setValue(NULL, 'cgi-fcgi');
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);

    // Use the test-only implementations of the regular and cron update stages.
    $overrides = [
      'automatic_updates.cron_update_stage' => TestCronUpdateStage::class,
      ConsoleUpdateStage::class => TestConsoleUpdateStage::class,
    ];
    foreach ($overrides as $service_id => $class) {
      if ($container->hasDefinition($service_id)) {
        $container->getDefinition($service_id)->setClass($class);
      }
    }
  }

  /**
   * Performs an update using the console update stage directly.
   */
  protected function performConsoleUpdate(): void {
    $this->container->get(ConsoleUpdateStage::class)->performUpdate();
  }

}

/**
 * A test-only version of the cron update stage to override and expose internals.
 */
class TestCronUpdateStage extends CronUpdateStage {

  /**
   * Expected exception message if terminal command is invoked.
   */
  public const EXPECTED_TERMINAL_EXCEPTION = 'Expected exception: Terminal command will not work in kernel tests';

  /**
   * {@inheritdoc}
   */
  public function runTerminalUpdateCommand(): void {
    // Invoking the terminal command will not work and is not necessary in
    // kernel tests. Throw an exception for tests that need to assert that
    // the terminal command would have been invoked.
    throw new \Exception(static::EXPECTED_TERMINAL_EXCEPTION);
  }

}

/**
 * A test-only version of the drush update stage to override and expose internals.
 */
class TestConsoleUpdateStage extends ConsoleUpdateStage {

  /**
   * {@inheritdoc}
   */
  public function apply(?int $timeout = 600): void {
    parent::apply($timeout);

    if (\Drupal::state()->get('system.maintenance_mode')) {
      $this->logger->info('Unattended update was applied in maintenance mode.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postApply(): void {
    if (\Drupal::state()->get('system.maintenance_mode')) {
      $this->logger->info('postApply() was called in maintenance mode.');
    }
    parent::postApply();
  }

  /**
   * {@inheritdoc}
   */
  protected function triggerPostApply(string $stage_id, string $start_version, string $target_version, bool $is_from_web): void {
    $this->handlePostApply($stage_id, $start_version, $target_version);
  }

}
