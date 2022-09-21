<?php

namespace Drupal\Tests\automatic_updates\Kernel;

use Drupal\automatic_updates\CronUpdater;
use Drupal\automatic_updates\Updater;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Url;
use Drupal\Tests\automatic_updates\Traits\ValidationTestTrait;
use Drupal\Tests\package_manager\Kernel\PackageManagerKernelTestBase;
use Drupal\Tests\package_manager\Kernel\TestStageTrait;

/**
 * Base class for kernel tests of the Automatic Updates module.
 */
abstract class AutomaticUpdatesKernelTestBase extends PackageManagerKernelTestBase {

  use ValidationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'automatic_updates_test_cron',
  ];

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
    // Always disable the Xdebug validator to allow test to run with Xdebug on.
    $this->disableValidators[] = 'automatic_updates.validator.xdebug';
    parent::setUp();

    // By default, pretend we're running Drupal core 9.8.0 and a non-security
    // update to 9.8.1 is available.
    $this->setCoreVersion('9.8.0');
    $this->setReleaseMetadata(['drupal' => __DIR__ . '/../../../package_manager/tests/fixtures/release-history/drupal.9.8.1-security.xml']);

    // Set a last cron run time so that the cron frequency validator will run
    // from a sane state.
    // @see \Drupal\automatic_updates\Validator\CronFrequencyValidator
    $this->container->get('state')->set('system.cron_last', time());

    // @todo Remove this when TUF integration is stable.
    $this->container->get('automatic_updates_test_cron.enabler')->enableCron();
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);

    // Use the test-only implementations of the regular and cron updaters.
    $overrides = [
      'automatic_updates.updater' => TestUpdater::class,
      'automatic_updates.cron_updater' => TestCronUpdater::class,
    ];
    foreach ($overrides as $service_id => $class) {
      if ($container->hasDefinition($service_id)) {
        $container->getDefinition($service_id)->setClass($class);
      }
    }
  }

}

/**
 * A test-only version of the regular updater to override internals.
 */
class TestUpdater extends Updater {

  use TestStageTrait;

  /**
   * {@inheritdoc}
   */
  public function setMetadata(string $key, $data): void {
    parent::setMetadata($key, $data);
  }

}

/**
 * A test-only version of the cron updater to override and expose internals.
 */
class TestCronUpdater extends CronUpdater {

  use TestStageTrait;

  /**
   * {@inheritdoc}
   */
  protected function triggerPostApply(Url $url): void {
    // Subrequests don't work in kernel tests, so just call the post-apply
    // handler directly.
    $parameters = $url->getRouteParameters();
    $this->handlePostApply($parameters['stage_id'], $parameters['installed_version'], $parameters['target_version']);
  }

}
