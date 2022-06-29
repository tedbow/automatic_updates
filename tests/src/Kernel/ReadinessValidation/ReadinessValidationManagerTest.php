<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessValidation;

use Drupal\automatic_updates\CronUpdater;
use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\automatic_updates\Updater;
use Drupal\automatic_updates_test\EventSubscriber\TestSubscriber1;
use Drupal\automatic_updates_test2\EventSubscriber\TestSubscriber2;
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
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setCoreVersion('9.8.2');
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
    TestSubscriber1::setTestResult($expected_results[0], ReadinessCheckEvent::class);
    TestSubscriber2::setTestResult($expected_results[1], ReadinessCheckEvent::class);
    $expected_results_all = array_merge($expected_results[0], $expected_results[1]);
    $this->assertCheckerResultsFromManager($expected_results_all, TRUE);

    // Define a constant flag that will cause the readiness checker
    // service priority to be altered.
    define('PACKAGE_MANAGER_TEST_VALIDATOR_PRIORITY', 1);
    // Rebuild the container to trigger the service to be altered.
    $kernel = $this->container->get('kernel');
    $this->container = $kernel->rebuildContainer();
    // The stored results should be returned, even though the validators' order
    // has been changed and the container has been rebuilt.
    $this->assertValidationResultsEqual($expected_results_all, $this->getResultsFromManager());
    // Confirm that after calling run() the expected results order has changed.
    $expected_results_all_reversed = array_reverse($expected_results_all);
    $this->assertCheckerResultsFromManager($expected_results_all_reversed, TRUE);

    $expected_results = [
      $this->testResults['checker_1']['2 errors 2 warnings'],
      $this->testResults['checker_2']['2 errors 2 warnings'],
    ];
    TestSubscriber1::setTestResult($expected_results[0], ReadinessCheckEvent::class);
    TestSubscriber2::setTestResult($expected_results[1], ReadinessCheckEvent::class);
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
    TestSubscriber1::setTestResult($expected_results[0], ReadinessCheckEvent::class);
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
    TestSubscriber1::setTestResult($expected_results[0], ReadinessCheckEvent::class);
    TestSubscriber2::setTestResult($expected_results[1], ReadinessCheckEvent::class);
    $this->container->get('module_installer')->install(['automatic_updates_test2']);
    $expected_results_all = array_merge($expected_results[0], $expected_results[1]);
    $this->assertCheckerResultsFromManager($expected_results_all);

    // Confirm that the checkers are run when a module that does not provide a
    // readiness checker is installed.
    $expected_results = [
      array_pop($this->testResults['checker_1']),
      array_pop($this->testResults['checker_2']),
    ];
    TestSubscriber1::setTestResult($expected_results[0], ReadinessCheckEvent::class);
    TestSubscriber2::setTestResult($expected_results[1], ReadinessCheckEvent::class);
    $expected_results_all = array_merge($expected_results[0], $expected_results[1]);
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
    TestSubscriber1::setTestResult($expected_results[0], ReadinessCheckEvent::class);
    TestSubscriber2::setTestResult($expected_results[1], ReadinessCheckEvent::class);
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
    TestSubscriber1::setTestResult($expected_results[0], ReadinessCheckEvent::class);
    TestSubscriber2::setTestResult(array_pop($this->testResults['checker_2']), ReadinessCheckEvent::class);
    $this->container->get('module_installer')->uninstall(['automatic_updates_test2']);
    $this->assertCheckerResultsFromManager($expected_results[0]);

    // Confirm that the checkers are run when a module that does not provide a
    // readiness checker is uninstalled.
    $expected_results = [
      array_pop($this->testResults['checker_1']),
    ];
    TestSubscriber1::setTestResult($expected_results[0], ReadinessCheckEvent::class);
    $this->container->get('module_installer')->uninstall(['help']);
    $this->assertCheckerResultsFromManager($expected_results[0]);
  }

  /**
   * @covers ::runIfNoStoredResults
   * @covers ::clearStoredResults
   */
  public function testRunIfNeeded(): void {
    $expected_results = array_pop($this->testResults['checker_1']);
    TestSubscriber1::setTestResult($expected_results, ReadinessCheckEvent::class);
    $this->container->get('module_installer')->install(['automatic_updates', 'automatic_updates_test2']);
    $this->assertCheckerResultsFromManager($expected_results);

    $unexpected_results = array_pop($this->testResults['checker_1']);
    TestSubscriber1::setTestResult($unexpected_results, ReadinessCheckEvent::class);
    $manager = $this->container->get('automatic_updates.readiness_validation_manager');
    // Confirm that the new results will not be returned because the checkers
    // will not be run.
    $manager->runIfNoStoredResults();
    $this->assertCheckerResultsFromManager($expected_results);

    // Confirm that the new results will be returned because the checkers will
    // be run if the stored results are deleted.
    $manager->clearStoredResults();
    $expected_results = $unexpected_results;
    $manager->runIfNoStoredResults();
    $this->assertCheckerResultsFromManager($expected_results);

    // Confirm that the results are the same after rebuilding the container.
    $unexpected_results = array_pop($this->testResults['checker_1']);
    TestSubscriber1::setTestResult($unexpected_results, ReadinessCheckEvent::class);
    /** @var \Drupal\Core\DrupalKernel $kernel */
    $kernel = $this->container->get('kernel');
    $this->container = $kernel->rebuildContainer();
    $this->assertCheckerResultsFromManager($expected_results);
  }

  /**
   * Tests the Automatic Updates cron setting changes which stage class is used.
   */
  public function testCronSetting(): void {
    $this->enableModules(['automatic_updates']);
    $stage = NULL;
    $listener = function (ReadinessCheckEvent $event) use (&$stage): void {
      $stage = $event->getStage();
    };
    $event_dispatcher = $this->container->get('event_dispatcher');
    $event_dispatcher->addListener(ReadinessCheckEvent::class, $listener);
    $this->container->get('automatic_updates.readiness_validation_manager')->run();
    // By default, updates will be enabled on cron.
    $this->assertInstanceOf(CronUpdater::class, $stage);
    $this->config('automatic_updates.settings')
      ->set('cron', CronUpdater::DISABLED)
      ->save();
    $this->container->get('automatic_updates.readiness_validation_manager')->run();
    $this->assertInstanceOf(Updater::class, $stage);
  }

  /**
   * Tests that stored validation results are deleted after an update.
   */
  public function testStoredResultsDeletedPostApply(): void {
    $this->enableModules(['automatic_updates']);
    $this->setCoreVersion('9.8.0');
    $this->setReleaseMetadata(['drupal' => __DIR__ . '/../../../fixtures/release-history/drupal.9.8.1-security.xml']);

    // The readiness checker should raise a warning, so that the update is not
    // blocked or aborted.
    $results = $this->testResults['checker_1']['1 warning'];
    TestSubscriber1::setTestResult($results, ReadinessCheckEvent::class);

    // Ensure that the validation manager collects the warning.
    /** @var \Drupal\automatic_updates\Validation\ReadinessValidationManager $manager */
    $manager = $this->container->get('automatic_updates.readiness_validation_manager')
      ->run();
    $this->assertValidationResultsEqual($results, $manager->getResults());
    TestSubscriber1::setTestResult(NULL, ReadinessCheckEvent::class);
    // Even though the checker no longer returns any results, the previous
    // results should be stored.
    $this->assertValidationResultsEqual($results, $manager->getResults());

    // Don't validate staged projects or scaffold file permissions because
    // actual staging operations are bypassed by package_manager_bypass, which
    // will make these validators complain that there is no actual Composer data
    // for them to inspect.
    $validators = array_map([$this->container, 'get'], [
      'automatic_updates.staged_projects_validator',
      'automatic_updates.validator.scaffold_file_permissions',
    ]);
    $event_dispatcher = $this->container->get('event_dispatcher');
    array_walk($validators, [$event_dispatcher, 'removeSubscriber']);

    /** @var \Drupal\automatic_updates\Updater $updater */
    $updater = $this->container->get('automatic_updates.updater');
    $updater->begin(['drupal' => '9.8.1']);
    $updater->stage();
    $updater->apply();
    $updater->postApply();
    $updater->destroy();

    // The readiness validation manager shouldn't have any stored results.
    $this->assertEmpty($manager->getResults());
  }

  /**
   * Tests that certain config changes clear stored results.
   */
  public function testStoredResultsClearedOnConfigChanges(): void {
    $this->enableModules(['automatic_updates']);

    $results = $this->testResults['checker_1']['1 error'];
    TestSubscriber1::setTestResult($results, ReadinessCheckEvent::class);
    $this->assertCheckerResultsFromManager($results, TRUE);
    // The results should be stored.
    $this->assertCheckerResultsFromManager($results, FALSE);
    // Changing the configured path to rsync should not clear the results.
    $this->config('package_manager.settings')
      ->set('executables.rsync', '/path/to/rsync')
      ->save();
    $this->assertCheckerResultsFromManager($results, FALSE);
    // Changing the configured path to Composer should clear the results.
    $this->config('package_manager.settings')
      ->set('executables.composer', '/path/to/composer')
      ->save();
    $this->assertNull($this->getResultsFromManager(FALSE));
  }

}
