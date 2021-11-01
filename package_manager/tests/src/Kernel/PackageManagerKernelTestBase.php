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
   * Asserts validation results are returned from a stage life cycle event.
   *
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results.
   * @param string $event_class
   *   The class of the event which should return the results.
   */
  protected function assertResults(array $expected_results, string $event_class): void {
    $stage = new TestStage(
      $this->container->get('package_manager.path_locator'),
      $this->container->get('package_manager.beginner'),
      $this->container->get('package_manager.stager'),
      $this->container->get('package_manager.committer'),
      $this->container->get('package_manager.cleaner'),
      $this->container->get('event_dispatcher'),
    );
    try {
      $stage->create();
      $stage->require(['drupal/core:9.8.1']);
      $stage->apply();
      $stage->destroy();
    }
    catch (StageException $e) {
      $this->assertValidationResultsEqual($expected_results, $e->getResults());
      // TestStage::dispatch() attaches the event object to the exception so
      // that we can analyze it.
      $this->assertInstanceOf($event_class, $e->event);
    }
    // If no errors are raised, we won't have asserted anything and the test
    // will be marked as risky. To prevent that, assert an eternal truth.
    $this->assertTrue(TRUE);
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
