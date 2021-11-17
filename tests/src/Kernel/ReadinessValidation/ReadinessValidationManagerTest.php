<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessValidation;

use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\automatic_updates_test\ReadinessChecker\TestChecker1;
use Drupal\automatic_updates_test2\ReadinessChecker\TestChecker2;
use Drupal\system\SystemManager;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;

/**
 * @coversDefaultClass \Drupal\automatic_updates\Validation\ReadinessValidationManager
 *
 * @group automatic_updates
 */
class ReadinessValidationManagerTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'automatic_updates_test',
    'package_manager',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->createTestValidationResults();
  }

  /**
   * @covers ::getResults
   */
  public function testGetResults(): void {
    $this->enableModules(['automatic_updates', 'automatic_updates_test2']);
    $this->assertCheckerResultsFromManager([], TRUE);

    $expected_results = [
      array_pop($this->testResults['checker_1']),
      array_pop($this->testResults['checker_2']),
    ];
    TestChecker1::setTestResult($expected_results[0], ReadinessCheckEvent::class);
    TestChecker2::setTestResult($expected_results[1], ReadinessCheckEvent::class);
    $expected_results_all = array_merge($expected_results[0], $expected_results[1]);
    $this->assertCheckerResultsFromManager($expected_results_all, TRUE);

    // Define a constant flag that will cause the readiness checker
    // service priority to be altered.
    define('PACKAGE_MANAGER_TEST_VALIDATOR_PRIORITY', 1);
    // Rebuild the container to trigger the service to be altered.
    $kernel = $this->container->get('kernel');
    $this->container = $kernel->rebuildContainer();
    // Confirm that results will be NULL if the run() is not called again
    // because the readiness checker services order has been altered.
    $this->assertNull($this->getResultsFromManager());
    // Confirm that after calling run() the expected results order has changed.
    $expected_results_all_reversed = array_reverse($expected_results_all);
    $this->assertCheckerResultsFromManager($expected_results_all_reversed, TRUE);

    $expected_results = [
      $this->testResults['checker_1']['2 errors 2 warnings'],
      $this->testResults['checker_2']['2 errors 2 warnings'],
    ];
    TestChecker1::setTestResult($expected_results[0], ReadinessCheckEvent::class);
    TestChecker2::setTestResult($expected_results[1], ReadinessCheckEvent::class);
    $expected_results_all = array_merge($expected_results[1], $expected_results[0]);
    $this->assertCheckerResultsFromManager($expected_results_all, TRUE);

    // Confirm that filtering by severity works.
    $warnings_only_results = [
      $expected_results[1]['2:warnings'],
      $expected_results[0]['1:warnings'],
    ];
    $this->assertCheckerResultsFromManager($warnings_only_results, FALSE, SystemManager::REQUIREMENT_WARNING);

    $errors_only_results = [
      $expected_results[1]['2:errors'],
      $expected_results[0]['1:errors'],
    ];
    $this->assertCheckerResultsFromManager($errors_only_results, FALSE, SystemManager::REQUIREMENT_ERROR);
  }

  /**
   * Tests that the manager is run after modules are installed.
   */
  public function testRunOnInstall(): void {
    $expected_results = [array_pop($this->testResults['checker_1'])];
    TestChecker1::setTestResult($expected_results[0], ReadinessCheckEvent::class);
    // Confirm that messages from an existing module are displayed when
    // 'automatic_updates' is installed.
    $this->container->get('module_installer')->install(['automatic_updates']);
    $this->assertCheckerResultsFromManager($expected_results[0]);

    // Confirm that the checkers are run when a module that provides a readiness
    // checker is installed.
    $expected_results = [
      array_pop($this->testResults['checker_1']),
      array_pop($this->testResults['checker_2']),
    ];
    TestChecker1::setTestResult($expected_results[0], ReadinessCheckEvent::class);
    TestChecker2::setTestResult($expected_results[1], ReadinessCheckEvent::class);
    $this->container->get('module_installer')->install(['automatic_updates_test2']);
    $expected_results_all = array_merge($expected_results[0], $expected_results[1]);
    $this->assertCheckerResultsFromManager($expected_results_all);

    // Confirm that the checkers are not run when a module that does not provide
    // a readiness checker is installed.
    $unexpected_results = [
      array_pop($this->testResults['checker_1']),
      array_pop($this->testResults['checker_2']),
    ];
    TestChecker1::setTestResult($unexpected_results[0], ReadinessCheckEvent::class);
    TestChecker2::setTestResult($unexpected_results[1], ReadinessCheckEvent::class);
    $this->container->get('module_installer')->install(['help']);
    $this->assertCheckerResultsFromManager($expected_results_all);
  }

  /**
   * Tests that the manager is run after modules are uninstalled.
   */
  public function testRunOnUninstall(): void {
    $expected_results = [
      array_pop($this->testResults['checker_1']),
      array_pop($this->testResults['checker_2']),
    ];
    TestChecker1::setTestResult($expected_results[0], ReadinessCheckEvent::class);
    TestChecker2::setTestResult($expected_results[1], ReadinessCheckEvent::class);
    // Confirm that messages from existing modules are displayed when
    // 'automatic_updates' is installed.
    $this->container->get('module_installer')->install(['automatic_updates', 'automatic_updates_test2', 'help']);
    $expected_results_all = array_merge($expected_results[0], $expected_results[1]);
    $this->assertCheckerResultsFromManager($expected_results_all);

    // Confirm that the checkers are run when a module that provides a readiness
    // checker is uninstalled.
    $expected_results = [
      array_pop($this->testResults['checker_1']),
    ];
    TestChecker1::setTestResult($expected_results[0], ReadinessCheckEvent::class);
    TestChecker2::setTestResult(array_pop($this->testResults['checker_2']), ReadinessCheckEvent::class);
    $this->container->get('module_installer')->uninstall(['automatic_updates_test2']);
    $this->assertCheckerResultsFromManager($expected_results[0]);

    // Confirm that the checkers are not run when a module that does provide a
    // readiness checker is uninstalled.
    $unexpected_results = [
      array_pop($this->testResults['checker_1']),
    ];
    TestChecker1::setTestResult($unexpected_results[0], ReadinessCheckEvent::class);
    $this->container->get('module_installer')->uninstall(['help']);
    $this->assertCheckerResultsFromManager($expected_results[0]);
  }

  /**
   * @covers ::runIfNoStoredResults
   */
  public function testRunIfNeeded(): void {
    $expected_results = array_pop($this->testResults['checker_1']);
    TestChecker1::setTestResult($expected_results, ReadinessCheckEvent::class);
    $this->container->get('module_installer')->install(['automatic_updates', 'automatic_updates_test2']);
    $this->assertCheckerResultsFromManager($expected_results);

    $unexpected_results = array_pop($this->testResults['checker_1']);
    TestChecker1::setTestResult($unexpected_results, ReadinessCheckEvent::class);
    $manager = $this->container->get('automatic_updates.readiness_validation_manager');
    // Confirm that the new results will not be returned because the checkers
    // will not be run.
    $manager->runIfNoStoredResults();
    $this->assertCheckerResultsFromManager($expected_results);

    // Confirm that the new results will be returned because the checkers will
    // be run if the stored results are deleted.
    /** @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface $key_value */
    $key_value = $this->container->get('keyvalue.expirable')->get('automatic_updates');
    $key_value->delete('readiness_validation_last_run');
    $expected_results = $unexpected_results;
    $manager->runIfNoStoredResults();
    $this->assertCheckerResultsFromManager($expected_results);

    // Confirm that the results are the same after rebuilding the container.
    $unexpected_results = array_pop($this->testResults['checker_1']);
    TestChecker1::setTestResult($unexpected_results, ReadinessCheckEvent::class);
    /** @var \Drupal\Core\DrupalKernel $kernel */
    $kernel = $this->container->get('kernel');
    $this->container = $kernel->rebuildContainer();
    $this->assertCheckerResultsFromManager($expected_results);

    // Define a constant flag that will cause the readiness checker
    // service priority to be altered. This will cause the priority of
    // 'automatic_updates_test.checker' to change from 2 to 4 which will be now
    // higher than 'automatic_updates_test2.checker' which has a priority of 3.
    // Because the list of checker IDs is not identical to the previous checker
    // run runIfNoStoredValidResults() will run the checkers again.
    define('PACKAGE_MANAGER_TEST_VALIDATOR_PRIORITY', 1);

    // Rebuild the container to trigger the readiness checker services to be
    // reordered.
    $kernel = $this->container->get('kernel');
    $this->container = $kernel->rebuildContainer();
    $expected_results = $unexpected_results;
    /** @var \Drupal\automatic_updates\Validation\ReadinessValidationManager $manager */
    $manager = $this->container->get('automatic_updates.readiness_validation_manager');
    $manager->runIfNoStoredResults();
    $this->assertCheckerResultsFromManager($expected_results);
  }

}
