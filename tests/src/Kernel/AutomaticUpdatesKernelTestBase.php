<?php

namespace Drupal\Tests\automatic_updates\Kernel;

use Drupal\automatic_updates\CronUpdater;
use Drupal\automatic_updates\Updater;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Url;
use Drupal\Tests\automatic_updates\Traits\ValidationTestTrait;
use Drupal\Tests\package_manager\Kernel\PackageManagerKernelTestBase;
use Drupal\Tests\package_manager\Kernel\TestStageTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;

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
    'system',
    'update',
    'update_test',
  ];

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
    // If Package Manager's symlink validator is disabled, also disable the
    // Automatic Updates validator which wraps it.
    if (in_array('package_manager.validator.symlink', $this->disableValidators, TRUE)) {
      $this->disableValidators[] = 'automatic_updates.validator.symlink';
    }
    // Always disable the Xdebug validator to allow test to run with Xdebug on.
    $this->disableValidators[] = 'automatic_updates.validator.xdebug';
    parent::setUp();

    // The Update module's default configuration must be installed for our
    // fake release metadata to be fetched.
    $this->installConfig('update');

    // Make the update system think that all of System's post-update functions
    // have run.
    $this->registerPostUpdateFunctions();

    // By default, pretend we're running Drupal core 9.8.0 and a non-security
    // update to 9.8.1 is available.
    $this->setCoreVersion('9.8.0');
    $this->setReleaseMetadata(['drupal' => __DIR__ . '/../../fixtures/release-history/drupal.9.8.1-security.xml']);

    // Set a last cron run time so that the cron frequency validator will run
    // from a sane state.
    // @see \Drupal\automatic_updates\Validator\CronFrequencyValidator
    $this->container->get('state')->set('system.cron_last', time());

    // @todo Remove this when TUF integration is stable.
    $this->container->get('automatic_updates_test_cron.enabler')->enableCron();
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
   * @param string[] $files
   *   The paths of the XML metadata files to use, keyed by project name.
   */
  protected function setReleaseMetadata(array $files): void {
    $responses = [];

    foreach ($files as $project => $file) {
      $metadata = Utils::tryFopen($file, 'r');
      $responses["/release-history/$project/current"] = new Response(200, [], Utils::streamFor($metadata));
    }
    $callable = function (RequestInterface $request) use ($responses): Response {
      return $responses[$request->getUri()->getPath()] ?? new Response(404);
    };

    // The mock handler's queue consist of same callable as many times as the
    // number of requests we expect to be made for update XML because it will
    // retrieve one item off the queue for each request.
    // @see \GuzzleHttp\Handler\MockHandler::__invoke()
    $handler = new MockHandler(array_fill(0, count($responses), $callable));
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
