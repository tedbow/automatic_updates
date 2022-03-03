<?php

namespace Drupal\Tests\automatic_updates\Kernel;

use Drupal\automatic_updates\CronUpdater;
use Drupal\automatic_updates\Updater;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\package_manager\Exception\StageValidationException;
use Drupal\Tests\automatic_updates\Traits\ValidationTestTrait;
use Drupal\Tests\package_manager\Kernel\PackageManagerKernelTestBase;
use Drupal\Tests\package_manager\Kernel\TestStage;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;

/**
 * Base class for kernel tests of the Automatic Updates module.
 */
abstract class AutomaticUpdatesKernelTestBase extends PackageManagerKernelTestBase {

  use ValidationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'update', 'update_test'];

  /**
   * The mocked HTTP client that returns metadata about available updates.
   *
   * We need to preserve this as a class property so that we can re-inject it
   * into the container when a rebuild is triggered by module installation.
   *
   * @var \GuzzleHttp\Client
   *
   * @see ::register()
   */
  private $client;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // If Package Manager's file system permissions validator is disabled, also
    // disable the Automatic Updates validator which wraps it.
    if (in_array('package_manager.validator.file_system', $this->disableValidators, TRUE)) {
      $this->disableValidators[] = 'automatic_updates.validator.file_system_permissions';
    }
    parent::setUp();

    // The Update module's default configuration must be installed for our
    // fake release metadata to be fetched.
    $this->installConfig('update');

    // Make the update system think that all of System's post-update functions
    // have run. Since kernel tests don't normally install modules and register
    // their updates, we need to do this so that all validators are tested from
    // a clean, fully up-to-date state.
    $updates = $this->container->get('update.post_update_registry')
      ->getPendingUpdateFunctions();

    $this->container->get('keyvalue')
      ->get('post_update')
      ->set('existing_updates', $updates);

    // By default, pretend we're running Drupal core 9.8.0 and a non-security
    // update to 9.8.1 is available.
    $this->setCoreVersion('9.8.1');
    $this->setReleaseMetadata(__DIR__ . '/../../fixtures/release-history/drupal.9.8.2.xml');

    // Set a last cron run time so that the cron frequency validator will run
    // from a sane state.
    // @see \Drupal\automatic_updates\Validator\CronFrequencyValidator
    $this->container->get('state')->set('system.cron_last', time());
  }

  /**
   * Sets the current (running) version of core, as known to the Update module.
   *
   * @param string $version
   *   The current version of core.
   */
  protected function setCoreVersion(string $version): void {
    $this->config('update_test.settings')
      ->set('system_info.#all.version', $version)
      ->save();
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);

    // If we previously set up a mock HTTP client in ::setReleaseMetadata(),
    // re-inject it into the container.
    if ($this->client) {
      $container->set('http_client', $this->client);
    }

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

  /**
   * Sets the release metadata file to use when fetching available updates.
   *
   * @param string $file
   *   The path of the XML metadata file to use.
   */
  protected function setReleaseMetadata(string $file): void {
    $metadata = Utils::tryFopen($file, 'r');
    $response = new Response(200, [], Utils::streamFor($metadata));
    $handler = new MockHandler([$response]);
    $this->client = new Client([
      'handler' => HandlerStack::create($handler),
    ]);
    $this->container->set('http_client', $this->client);
  }

}

/**
 * A test-only version of the regular updater to override internals.
 */
class TestUpdater extends Updater {

  /**
   * {@inheritdoc}
   */
  public function getStagingRoot(): string {
    return TestStage::$stagingRoot ?: parent::getStagingRoot();
  }

}

/**
 * A test-only version of the cron updater to override and expose internals.
 */
class TestCronUpdater extends CronUpdater {

  /**
   * {@inheritdoc}
   */
  public function getStagingRoot(): string {
    return TestStage::$stagingRoot ?: parent::getStagingRoot();
  }

  /**
   * {@inheritdoc}
   */
  public static function formatValidationException(StageValidationException $exception): string {
    return parent::formatValidationException($exception);
  }

}
