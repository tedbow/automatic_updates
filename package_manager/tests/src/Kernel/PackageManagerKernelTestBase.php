<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\package_manager\Event\StageEvent;
use Drupal\package_manager\Validator\DiskSpaceValidator;
use Drupal\package_manager\Exception\StageException;
use Drupal\package_manager\Exception\StageValidationException;
use Drupal\package_manager\PathLocator;
use Drupal\package_manager\Stage;
use Drupal\package_manager_test_fixture\EventSubscriber\FixtureStager;
use Drupal\Tests\package_manager\Traits\ValidationTestTrait;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use org\bovigo\vfs\vfsStreamFile;
use org\bovigo\vfs\visitor\vfsStreamAbstractVisitor;

/**
 * Base class for kernel tests of Package Manager's functionality.
 */
abstract class PackageManagerKernelTestBase extends KernelTestBase {

  use ValidationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'package_manager',
    'package_manager_bypass',
    'package_manager_test_fixture',
  ];

  /**
   * The test staging root.
   *
   * This value must be set before creating a test stage instance.
   *
   * @var string
   *
   * @see \Drupal\Tests\package_manager\Kernel\TestStageTrait::__construct()
   */
  public static $testStagingRoot;

  /**
   * The service IDs of any validators to disable.
   *
   * @var string[]
   */
  protected $disableValidators = [
    // Disable the filesystem permissions validator, since we cannot guarantee
    // that the current code base will be writable in all testing situations.
    // We test this validator functionally in Automatic Updates' build tests,
    // since those do give us control over the filesystem permissions.
    // @see \Drupal\Tests\automatic_updates\Build\CoreUpdateTest::assertReadOnlyFileSystemError()
    // @see \Drupal\Tests\package_manager\Kernel\WritableFileSystemValidatorTest
    'package_manager.validator.file_system',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig('package_manager');
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);

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
      $this->container->get('datetime.time')
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
   */
  protected function assertResults(array $expected_results, string $event_class = NULL): void {
    $stage = $this->createStage();

    try {
      $stage->create();
      $stage->require(['drupal/core:9.8.1']);
      $stage->apply();
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
   */
  protected function createTestProject(): void {
    // Create the active directory and copy its contents from a fixture.
    $active_dir = vfsStream::newDirectory('active');
    $this->vfsRoot->addChild($active_dir);
    vfsStream::copyFromFileSystem(__DIR__ . '/../../fixtures/fake_site', $active_dir);

    // Because we can't commit physical `.git` directories into the fixture, use
    // a visitor to traverse the virtual file system and rename all `_git`
    // directories to `.git`.
    vfsStream::inspect(new class () extends vfsStreamAbstractVisitor {

      /**
       * {@inheritdoc}
       */
      public function visitFile(vfsStreamFile $file) {}

      /**
       * {@inheritdoc}
       */
      public function visitDirectory(vfsStreamDirectory $dir) {
        if ($dir->getName() === '_git') {
          $dir->rename('.git');
        }
        foreach ($dir->getChildren() as $child) {
          $this->visit($child);
        }
      }

    });

    // Create a staging root directory alongside the active directory.
    $stage_dir = vfsStream::newDirectory('stage');
    $this->vfsRoot->addChild($stage_dir);
    static::$testStagingRoot = $stage_dir->url();

    $active_dir = $active_dir->url();
    $path_locator = $this->mockPathLocator($active_dir);

    // Ensure that the active directory is copied into the virtual staging area,
    // even if Package Manager's operations are bypassed.
    FixtureStager::setFixturePath($active_dir);

    // Since the path locator now points to a virtual file system, we need to
    // replace the disk space validator with a test-only version that bypasses
    // system calls, like disk_free_space() and stat(), which aren't supported
    // by vfsStream.
    $validator = new TestDiskSpaceValidator(
      $this->container->get('package_manager.path_locator'),
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
   * Mocks the path locator and injects it into the service container.
   *
   * @param string $project_root
   *   The project root.
   * @param string|null $vendor_dir
   *   (optional) The vendor directory. Defaults to `$project_root/vendor`.
   * @param string $web_root
   *   (optional) The web root, relative to the project root. Defaults to ''
   *   (i.e., same as the project root).
   *
   * @return \Drupal\package_manager\PathLocator
   *   The mocked path locator.
   */
  protected function mockPathLocator(string $project_root, string $vendor_dir = NULL, string $web_root = ''): PathLocator {
    if (empty($vendor_dir)) {
      $vendor_dir = $project_root . '/vendor';
    }
    $path_locator = $this->prophesize(PathLocator::class);
    $path_locator->getProjectRoot()->willReturn($project_root);
    $path_locator->getVendorDirectory()->willReturn($vendor_dir);
    $path_locator->getWebRoot()->willReturn($web_root);

    // We don't need the prophet anymore.
    $path_locator = $path_locator->reveal();
    $this->container->set('package_manager.path_locator', $path_locator);

    return $path_locator;
  }

}

/**
 * Common functions for test stages.
 */
trait TestStageTrait {

  /**
   * The directory where staging areas will be created.
   *
   * @var string
   */
  public static $stagingRoot;

  /**
   * {@inheritdoc}
   */
  public function __construct(...$arguments) {
    parent::__construct(...$arguments);
    $mirror = new \ReflectionClass(Stage::class);
    $this->tempStore->set($mirror->getConstant('TEMPSTORE_STAGING_ROOT_KEY'), PackageManagerKernelTestBase::$testStagingRoot);
  }

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
