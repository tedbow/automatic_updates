<?php

namespace Drupal\Tests\automatic_updates\Kernel;

use Drupal\automatic_updates\CronUpdater;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\package_manager\Exception\StageValidationException;
use Drupal\Tests\automatic_updates\Traits\ValidationTestTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;

/**
 * Base class for kernel tests of the Automatic Updates module.
 */
abstract class AutomaticUpdatesKernelTestBase extends KernelTestBase {

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
    $this->setCoreVersion('9.8.0');
    $this->setReleaseMetadata(__DIR__ . '/../../fixtures/release-history/drupal.9.8.1.xml');

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

    $this->disableValidators($container);
  }

  /**
   * Disables any validators that will interfere with this test.
   */
  protected function disableValidators(ContainerBuilder $container): void {
    // Disable the filesystem permissions validator, since we cannot guarantee
    // that the current code base will be writable in all testing situations.
    // We test this validator functionally in our build tests, since those do
    // give us control over the filesystem permissions.
    // @see \Drupal\Tests\automatic_updates\Build\CoreUpdateTest::assertReadOnlyFileSystemError()
    $container->removeDefinition('automatic_updates.validator.file_system_permissions');
    $container->removeDefinition('package_manager.validator.file_system');
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
 * A test-only version of the cron updater to expose internal methods.
 */
class TestCronUpdater extends CronUpdater {

  /**
   * The directory where staging areas will be created.
   *
   * @var string
   */
  public static $stagingRoot;

  /**
   * {@inheritdoc}
   */
  protected static function getStagingRoot(): string {
    return static::$stagingRoot ?: parent::getStagingRoot();
  }

  /**
   * {@inheritdoc}
   */
  public static function formatValidationException(StageValidationException $exception): string {
    return parent::formatValidationException($exception);
  }

}
