<?php

declare(strict_types = 1);

namespace Drupal\Tests\automatic_updates_extensions\Kernel;

use Drupal\package_manager\Event\PreOperationStageEvent;
use Drupal\package_manager\Exception\StageEventException;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;

/**
 * Base class for kernel tests of the Automatic Updates Extensions module.
 *
 * @internal
 */
abstract class AutomaticUpdatesExtensionsKernelTestBase extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'automatic_updates_extensions',
    'package_manager_test_release_history',
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
    $this->disableValidators[] = 'package_manager.validator.composer';
    parent::setUp();
  }

  /**
   * Create Test Project.
   *
   * @param string|null $source_dir
   *   Source directory.
   */
  protected function createTestProject(?string $source_dir = NULL): void {
    $source_dir = $source_dir ?? __DIR__ . '/../../fixtures/fake-site';
    parent::createTestProject($source_dir);
  }

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
    $updater = $this->container->get('automatic_updates_extensions.updater');

    try {
      $updater->begin($project_versions);
      $updater->stage();
      $updater->apply();
      $updater->postApply();
      $updater->destroy();

      // If we did not get an exception, ensure we didn't expect any results.
      $this->assertEmpty($expected_results);
    }
    catch (StageEventException $e) {
      $this->assertNotEmpty($expected_results);
      $exception_event = $e->event;
      $this->assertInstanceOf($event_class, $exception_event);
      $this->assertInstanceOf(PreOperationStageEvent::class, $exception_event);
      $this->assertValidationResultsEqual($expected_results, $e->event->getResults());
    }
  }

}
