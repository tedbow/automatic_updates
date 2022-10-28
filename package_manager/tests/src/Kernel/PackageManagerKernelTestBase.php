<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\StageEvent;
use Drupal\package_manager\StatusCheckTrait;
use Drupal\package_manager\Validator\DiskSpaceValidator;
use Drupal\package_manager\Exception\StageException;
use Drupal\package_manager\Exception\StageValidationException;
use Drupal\package_manager\Stage;
use Drupal\package_manager_bypass\Beginner;
use Drupal\Tests\package_manager\Traits\FixtureUtilityTrait;
use Drupal\Tests\package_manager\Traits\ValidationTestTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use org\bovigo\vfs\vfsStream;
use PhpTuf\ComposerStager\Domain\Value\Path\PathInterface;
use PhpTuf\ComposerStager\Infrastructure\Factory\Path\PathFactoryInterface;
use PhpTuf\ComposerStager\Infrastructure\Value\Path\AbstractPath;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Base class for kernel tests of Package Manager's functionality.
 */
abstract class PackageManagerKernelTestBase extends KernelTestBase {

  use FixtureUtilityTrait;
  use StatusCheckTrait;
  use ValidationTestTrait;

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
  protected static $modules = [
    'package_manager',
    'package_manager_bypass',
    'system',
    'update',
    'update_test',
  ];

  /**
   * The service IDs of any validators to disable.
   *
   * @var string[]
   */
  protected $disableValidators = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig('package_manager');

    $this->createVirtualProject();

    // The Update module's default configuration must be installed for our
    // fake release metadata to be fetched.
    $this->installConfig('update');

    // Make the update system think that all of System's post-update functions
    // have run.
    $this->registerPostUpdateFunctions();
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

    // Ensure that Composer Stager uses the test path factory, which is aware
    // of the virtual file system.
    $definition = new Definition(TestPathFactory::class);
    $class = $definition->getClass();
    $container->setDefinition($class, $definition->setPublic(FALSE));
    $container->setAlias(PathFactoryInterface::class, $class);

    // When a virtual project is used, the disk space validator is replaced with
    // a mock. When staged changes are applied, the container is rebuilt, which
    // destroys the mocked service and can cause unexpected side effects. The
    // 'persist' tag prevents the mock from being destroyed during a container
    // rebuild.
    // @see ::createVirtualProject()
    $container->getDefinition('package_manager.validator.disk_space')
      ->addTag('persist');

    foreach ($this->disableValidators as $service_id) {
      if ($container->hasDefinition($service_id)) {
        $container->getDefinition($service_id)->clearTag('event_subscriber');
      }
    }
  }

  /**
   * Creates a stage object for testing purposes.
   *
   * @return \Drupal\Tests\package_manager\Kernel\TestStage
   *   A stage object, with test-only modifications.
   */
  protected function createStage(): TestStage {
    return new TestStage(
      $this->container->get('config.factory'),
      $this->container->get('package_manager.path_locator'),
      $this->container->get('package_manager.beginner'),
      $this->container->get('package_manager.stager'),
      $this->container->get('package_manager.committer'),
      $this->container->get('file_system'),
      $this->container->get('event_dispatcher'),
      $this->container->get('tempstore.shared'),
      $this->container->get('datetime.time'),
      new TestPathFactory(),
      $this->container->get('package_manager.failure_marker')
    );
  }

  /**
   * Asserts validation results are returned from a stage life cycle event.
   *
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results.
   * @param string|null $event_class
   *   (optional) The class of the event which should return the results. Must
   *   be passed if $expected_results is not empty.
   *
   * @return \Drupal\package_manager\Stage
   *   The stage that was used to collect the validation results.
   */
  protected function assertResults(array $expected_results, string $event_class = NULL): Stage {
    $stage = $this->createStage();

    try {
      $stage->create();
      $stage->require(['drupal/core:9.8.1']);
      $stage->apply();
      $stage->postApply();
      $stage->destroy();

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
    return $stage;
  }

  /**
   * Asserts validation results are returned from the status check event.
   *
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results.
   * @param \Drupal\package_manager\Stage|null $stage
   *   (optional) The stage to use to create the status check event. If none is
   *   provided a new stage will be created.
   */
  protected function assertStatusCheckResults(array $expected_results, Stage $stage = NULL): void {
    $actual_results = $this->runStatusCheck($stage ?? $this->createStage(), $this->container->get('event_dispatcher'));
    $this->assertValidationResultsEqual($expected_results, $actual_results);
  }

  /**
   * Marks all pending post-update functions as completed.
   *
   * Since kernel tests don't normally install modules and register their
   * updates, this method makes sure that we are testing from a clean, fully
   * up-to-date state.
   */
  protected function registerPostUpdateFunctions(): void {
    $updates = $this->container->get('update.post_update_registry')
      ->getPendingUpdateFunctions();

    $this->container->get('keyvalue')
      ->get('post_update')
      ->set('existing_updates', $updates);
  }

  /**
   * Creates a test project in a virtual file system.
   *
   * This will create two directories at the root of the virtual file system:
   * 'active', which is the active directory containing a fake Drupal code base,
   * and 'stage', which is the root directory used to stage changes. The path
   * locator service will also be mocked so that it points to the test project.
   *
   * @param string|null $source_dir
   *   (optional) The path of a directory which should be copied into the
   *   virtual file system and used as the active directory.
   */
  protected function createVirtualProject(?string $source_dir = NULL): void {
    $source_dir = $source_dir ?? __DIR__ . '/../../fixtures/fake_site';

    // Create the active directory and copy its contents from a fixture.
    $active_dir = vfsStream::newDirectory('active');
    $this->vfsRoot->addChild($active_dir);
    $active_dir = $active_dir->url();
    static::copyFixtureFilesTo($source_dir, $active_dir);

    // Create a staging root directory alongside the active directory.
    $stage_dir = vfsStream::newDirectory('stage');
    $this->vfsRoot->addChild($stage_dir);

    // Ensure the path locator points to the virtual active directory. We assume
    // that is its own web root and that the vendor directory is at its top
    // level.
    /** @var \Drupal\package_manager_bypass\PathLocator $path_locator */
    $path_locator = $this->container->get('package_manager.path_locator');
    $path_locator->setPaths($active_dir, $active_dir . '/vendor', '', $stage_dir->url());

    // Ensure the active directory will be copied into the virtual staging area.
    Beginner::setFixturePath($active_dir);

    // Since the path locator now points to a virtual file system, we need to
    // replace the disk space validator with a test-only version that bypasses
    // system calls, like disk_free_space() and stat(), which aren't supported
    // by vfsStream. This validator will persist through container rebuilds.
    // @see ::register()
    $validator = new TestDiskSpaceValidator(
      $path_locator,
      $this->container->get('string_translation')
    );
    // By default, the validator should report that the root, vendor, and
    // temporary directories have basically infinite free space.
    $validator->freeSpace = [
      $path_locator->getProjectRoot() => PHP_INT_MAX,
      $path_locator->getVendorDirectory() => PHP_INT_MAX,
      $validator->temporaryDirectory() => PHP_INT_MAX,
    ];
    $this->container->set('package_manager.validator.disk_space', $validator);
  }

  /**
   * Copies a fixture directory into the active directory.
   *
   * @param string $active_fixture_dir
   *   Path to fixture active directory from which the files will be copied.
   */
  protected function copyFixtureFolderToActiveDirectory(string $active_fixture_dir) {
    $active_dir = $this->container->get('package_manager.path_locator')
      ->getProjectRoot();
    static::copyFixtureFilesTo($active_fixture_dir, $active_dir);
  }

  /**
   * Copies a fixture directory into the stage directory on apply.
   *
   * @param string $fixture_dir
   *   Path to fixture directory from which the files will be copied.
   */
  protected function copyFixtureFolderToStageDirectoryOnApply(string $fixture_dir) {
    /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher */
    $event_dispatcher = $this->container->get('event_dispatcher');

    $listener = function (PreApplyEvent $event) use ($fixture_dir): void {
      static::copyFixtureFilesTo($fixture_dir, $event->getStage()->getStageDirectory());
    };
    $event_dispatcher->addListener(PreApplyEvent::class, $listener, PHP_INT_MAX);
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
    $handler = new MockHandler(array_fill(0, 100, $callable));
    $this->client = new Client([
      'handler' => HandlerStack::create($handler),
    ]);
    $this->container->set('http_client', $this->client);
  }

}

/**
 * Common functions for test stages.
 */
trait TestStageTrait {

  /**
   * {@inheritdoc}
   */
  protected function dispatch(StageEvent $event, callable $on_error = NULL): void {
    try {
      parent::dispatch($event, $on_error);
    }
    catch (StageException $e) {
      // Attach the event object to the exception so that test code can verify
      // that the exception was thrown when a specific event was dispatched.
      $e->event = $event;
      throw $e;
    }
  }

}

/**
 * Defines a path value object that is aware of the virtual file system.
 */
class TestPath extends AbstractPath {

  /**
   * {@inheritdoc}
   */
  protected function doResolve(string $basePath): string {
    if (str_starts_with($this->path, vfsStream::SCHEME . '://')) {
      return $this->path;
    }
    return implode(DIRECTORY_SEPARATOR, [$basePath, $this->path]);
  }

}

/**
 * Defines a path factory that is aware of the virtual file system.
 */
class TestPathFactory implements PathFactoryInterface {

  /**
   * {@inheritdoc}
   */
  public static function create(string $path): PathInterface {
    return new TestPath($path);
  }

}

/**
 * Defines a stage specifically for testing purposes.
 */
class TestStage extends Stage {

  use TestStageTrait;

}

/**
 * A test version of the disk space validator to bypass system-level functions.
 */
class TestDiskSpaceValidator extends DiskSpaceValidator {

  /**
   * Whether the root and vendor directories are on the same logical disk.
   *
   * @var bool
   */
  public $sharedDisk = TRUE;

  /**
   * The amount of free space, keyed by path.
   *
   * @var float[]
   */
  public $freeSpace = [];

  /**
   * {@inheritdoc}
   */
  protected function stat(string $path): array {
    return [
      'dev' => $this->sharedDisk ? 'disk' : uniqid(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function freeSpace(string $path): float {
    return $this->freeSpace[$path];
  }

  /**
   * {@inheritdoc}
   */
  public function temporaryDirectory(): string {
    return 'temp';
  }

}
