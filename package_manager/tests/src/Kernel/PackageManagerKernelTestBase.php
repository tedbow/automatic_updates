<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\package_manager\Event\StageEvent;
use Drupal\package_manager\Stage;
use Drupal\package_manager\StageException;
use Drupal\Tests\package_manager\Traits\ValidationTestTrait;

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
  ];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);
    $this->disableValidators($container);
  }

  /**
   * Disables any validators that will interfere with this test.
   */
  protected function disableValidators(ContainerBuilder $container): void {
    // Disable the filesystem permissions validator, since we cannot guarantee
    // that the current code base will be writable in all testing situations.
    // We test this validator functionally in Automatic Updates' build tests,
    // since those do give us control over the filesystem permissions.
    // @see \Drupal\Tests\automatic_updates\Build\CoreUpdateTest::assertReadOnlyFileSystemError()
    // @see \Drupal\Tests\package_manager\Kernel\WritableFileSystemValidatorTest
    $container->removeDefinition('package_manager.validator.file_system');
  }

  /**
   * Creates a stage object for testing purposes.
   *
   * @return \Drupal\Tests\package_manager\Kernel\TestStage
   *   A stage object, with test-only modifications.
   */
  protected function createStage(): TestStage {
    return new TestStage(
      $this->container->get('package_manager.path_locator'),
      $this->container->get('package_manager.beginner'),
      $this->container->get('package_manager.stager'),
      $this->container->get('package_manager.committer'),
      $this->container->get('package_manager.cleaner'),
      $this->container->get('event_dispatcher'),
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
    catch (StageException $e) {
      $this->assertNotEmpty($expected_results);
      $this->assertValidationResultsEqual($expected_results, $e->getResults());
      // TestStage::dispatch() attaches the event object to the exception so
      // that we can analyze it.
      $this->assertNotEmpty($event_class);
      $this->assertInstanceOf($event_class, $e->event);
    }
  }

}

/**
 * Defines a stage specifically for testing purposes.
 */
class TestStage extends Stage {

  /**
   * {@inheritdoc}
   */
  protected function dispatch(StageEvent $event): void {
    try {
      parent::dispatch($event);
    }
    catch (StageException $e) {
      // Attach the event object to the exception so that test code can verify
      // that the exception was thrown when a specific event was dispatched.
      $e->event = $event;
      throw $e;
    }
  }

}
