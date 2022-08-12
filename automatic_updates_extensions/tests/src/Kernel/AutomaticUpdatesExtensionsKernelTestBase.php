<?php

namespace Drupal\Tests\automatic_updates_extensions\Kernel;

use Drupal\automatic_updates_extensions\ExtensionUpdater;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\package_manager\Exception\StageValidationException;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;
use Drupal\Tests\package_manager\Kernel\TestPathFactory;
use Drupal\Tests\package_manager\Kernel\TestStageTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;

/**
 * Base class for kernel tests of the Automatic Updates Extensions module.
 */
abstract class AutomaticUpdatesExtensionsKernelTestBase extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'automatic_updates_extensions',
    'automatic_updates_test_release_history',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // Disable the Composer executable validator, since it may cause the tests
    // to fail if a supported version of Composer is unavailable to the web
    // server. This should be okay in most situations because, apart from the
    // validator, only Composer Stager needs run Composer, and
    // package_manager_bypass is disabling those operations.
    $this->disableValidators[] = 'package_manager.validator.composer_executable';
    parent::setUp();
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);

    // Use the test-only implementations of the regular and cron updaters.
    $overrides = [
      'automatic_updates_extensions.updater' => TestExtensionUpdater::class,
    ];
    foreach ($overrides as $service_id => $class) {
      if ($container->hasDefinition($service_id)) {
        $container->getDefinition($service_id)->setClass($class);
      }
    }
  }

  /**
   * The client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $client;

  /**
   * Asserts validation results are returned from a stage life cycle event.
   *
   * @param string[] $project_versions
   *   The project versions.
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results.
   * @param string|null $event_class
   *   (optional) The class of the event which should return the results. Must
   *   be passed if $expected_results is not empty.
   */
  protected function assertUpdateResults(array $project_versions, array $expected_results, string $event_class = NULL): void {
    $updater = $this->createExtensionUpdater();

    try {
      $updater->begin($project_versions);
      $updater->stage();
      $updater->apply();
      $updater->postApply();
      $updater->destroy();

      // If we did not get an exception, ensure we didn't expect any results.
      $this->assertEmpty($expected_results);
    }
    catch (StageValidationException $e) {
      $this->assertNotEmpty($expected_results);
      $this->assertValidationResultsEqual($expected_results, $e->getResults());
      // TestStage::dispatch() attaches the event object to the exception so
      // that we can analyze it.
      $this->assertNotEmpty($event_class);
      $this->assertInstanceOf($event_class, $e->event);
    }
  }

  /**
   * Sets the release metadata file to use when fetching available updates.
   *
   * @param string[] $files
   *   The paths of the XML metadata files to use, keyed by project name.
   */
  protected function setReleaseMetadataForProjects(array $files): void {
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

  /**
   * Creates an extension updater object for testing purposes.
   *
   * @return \Drupal\Tests\automatic_updates_extensions\Kernel\TestExtensionUpdater
   *   A extension updater object, with test-only modifications.
   */
  protected function createExtensionUpdater(): TestExtensionUpdater {
    return new TestExtensionUpdater(
      $this->container->get('config.factory'),
      $this->container->get('package_manager.path_locator'),
      $this->container->get('package_manager.beginner'),
      $this->container->get('package_manager.stager'),
      $this->container->get('package_manager.committer'),
      $this->container->get('file_system'),
      $this->container->get('event_dispatcher'),
      $this->container->get('tempstore.shared'),
      $this->container->get('datetime.time'),
      new TestPathFactory()
    );
  }

}

/**
 * A test-only version of the regular extension updater to override internals.
 */
class TestExtensionUpdater extends ExtensionUpdater {

  use TestStageTrait;

}
