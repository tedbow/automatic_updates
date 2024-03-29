<?php

namespace Drupal\Tests\automatic_updates\Kernel\StatusCheck;

use Drupal\automatic_updates\CronUpdater;
use Drupal\automatic_updates\Updater;
use Drupal\automatic_updates_test\EventSubscriber\TestSubscriber1;
use Drupal\automatic_updates_test2\EventSubscriber\TestSubscriber2;
use Drupal\package_manager\Event\StatusCheckEvent;
use Drupal\system\SystemManager;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;

/**
 * @coversDefaultClass \Drupal\automatic_updates\Validation\StatusChecker
 *
 * @group automatic_updates
 */
class StatusCheckerTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'automatic_updates_test',
    'package_manager_test_validation',
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
  }

  /**
   * @covers ::getResults
   */
  public function testGetResults(): void {
    $this->container->get('module_installer')
      ->install(['automatic_updates', 'automatic_updates_test2']);
    $this->assertCheckerResultsFromManager([], TRUE);
    $checker_1_expected = [$this->createValidationResult(SystemManager::REQUIREMENT_ERROR)];
    $checker_2_expected = [$this->createValidationResult(SystemManager::REQUIREMENT_ERROR)];
    TestSubscriber1::setTestResult($checker_1_expected, StatusCheckEvent::class);
    TestSubscriber2::setTestResult($checker_2_expected, StatusCheckEvent::class);
    $expected_results_all = array_merge($checker_1_expected, $checker_2_expected);
    $this->assertCheckerResultsFromManager($expected_results_all, TRUE);

    // Define a constant flag that will cause the status checker service
    // priority to be altered.
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

    $checker_1_expected = [
      'checker 1 errors' => $this->createValidationResult(SystemManager::REQUIREMENT_ERROR),
      'checker 1 warnings' => $this->createValidationResult(SystemManager::REQUIREMENT_WARNING),
    ];
    $checker_2_expected = [
      'checker 2 errors' => $this->createValidationResult(SystemManager::REQUIREMENT_ERROR),
      'checker 2 warnings' => $this->createValidationResult(SystemManager::REQUIREMENT_WARNING),
    ];
    TestSubscriber1::setTestResult($checker_1_expected, StatusCheckEvent::class);
    TestSubscriber2::setTestResult($checker_2_expected, StatusCheckEvent::class);
    $expected_results_all = array_merge($checker_2_expected, $checker_1_expected);
    $this->assertCheckerResultsFromManager($expected_results_all, TRUE);

    // Confirm that filtering by severity works.
    $warnings_only_results = [
      $checker_2_expected['checker 2 warnings'],
      $checker_1_expected['checker 1 warnings'],
    ];
    $this->assertCheckerResultsFromManager($warnings_only_results, FALSE, SystemManager::REQUIREMENT_WARNING);

    $errors_only_results = [
      $checker_2_expected['checker 2 errors'],
      $checker_1_expected['checker 1 errors'],
    ];
    $this->assertCheckerResultsFromManager($errors_only_results, FALSE, SystemManager::REQUIREMENT_ERROR);
  }

  /**
   * Tests that the manager is run after modules are installed.
   */
  public function testRunOnInstall(): void {
    $checker_1_results = [$this->createValidationResult(SystemManager::REQUIREMENT_ERROR)];
    TestSubscriber1::setTestResult($checker_1_results, StatusCheckEvent::class);
    // Confirm that messages from an existing module are displayed when
    // 'automatic_updates' is installed.
    $this->container->get('module_installer')->install(['automatic_updates']);
    $this->assertCheckerResultsFromManager($checker_1_results);

    // Confirm that the checkers are run when a module that provides a status
    // checker is installed.
    $checker_1_results = [$this->createValidationResult(SystemManager::REQUIREMENT_ERROR)];
    $checker_2_results = [$this->createValidationResult(SystemManager::REQUIREMENT_ERROR)];
    TestSubscriber1::setTestResult($checker_1_results, StatusCheckEvent::class);
    TestSubscriber2::setTestResult($checker_2_results, StatusCheckEvent::class);
    $this->container->get('module_installer')->install(['automatic_updates_test2']);
    $expected_results_all = array_merge($checker_1_results, $checker_2_results);
    $this->assertCheckerResultsFromManager($expected_results_all);

    // Confirm that the checkers are run when a module that does not provide a
    // status checker is installed.
    $checker_1_results = [$this->createValidationResult(SystemManager::REQUIREMENT_ERROR)];
    $checker_2_results = [$this->createValidationResult(SystemManager::REQUIREMENT_ERROR)];
    TestSubscriber1::setTestResult($checker_1_results, StatusCheckEvent::class);
    TestSubscriber2::setTestResult($checker_2_results, StatusCheckEvent::class);
    $expected_results_all = array_merge($checker_1_results, $checker_2_results);
    $this->container->get('module_installer')->install(['help']);
    $this->assertCheckerResultsFromManager($expected_results_all);
  }

  /**
   * Tests that the manager is run after modules are uninstalled.
   */
  public function testRunOnUninstall(): void {
    $checker_1_results = [$this->createValidationResult(SystemManager::REQUIREMENT_ERROR)];
    $checker_2_results = [$this->createValidationResult(SystemManager::REQUIREMENT_ERROR)];
    TestSubscriber1::setTestResult($checker_1_results, StatusCheckEvent::class);
    TestSubscriber2::setTestResult($checker_2_results, StatusCheckEvent::class);
    // Confirm that messages from existing modules are displayed when
    // 'automatic_updates' is installed.
    $this->container->get('module_installer')->install(['automatic_updates', 'automatic_updates_test2', 'help']);
    $expected_results_all = array_merge($checker_1_results, $checker_2_results);
    $this->assertCheckerResultsFromManager($expected_results_all);

    // Confirm that the checkers are run when a module that provides a status
    // checker is uninstalled.
    $checker_1_results = [$this->createValidationResult(SystemManager::REQUIREMENT_ERROR)];
    $checker_2_results = [$this->createValidationResult(SystemManager::REQUIREMENT_ERROR)];
    TestSubscriber1::setTestResult($checker_1_results, StatusCheckEvent::class);
    TestSubscriber2::setTestResult($checker_2_results, StatusCheckEvent::class);
    $this->container->get('module_installer')->uninstall(['automatic_updates_test2']);
    $this->assertCheckerResultsFromManager($checker_1_results);

    // Confirm that the checkers are run when a module that does not provide a
    // status checker is uninstalled.
    $checker_1_results = [$this->createValidationResult(SystemManager::REQUIREMENT_ERROR)];
    TestSubscriber1::setTestResult($checker_1_results, StatusCheckEvent::class);
    $this->container->get('module_installer')->uninstall(['help']);
    $this->assertCheckerResultsFromManager($checker_1_results);
  }

  /**
   * @covers ::runIfNoStoredResults
   * @covers ::clearStoredResults
   */
  public function testRunIfNeeded(): void {
    $expected_results = [$this->createValidationResult(SystemManager::REQUIREMENT_ERROR)];
    TestSubscriber1::setTestResult($expected_results, StatusCheckEvent::class);
    $this->container->get('module_installer')->install(['automatic_updates', 'automatic_updates_test2']);
    $this->assertCheckerResultsFromManager($expected_results);

    $unexpected_results = [$this->createValidationResult(SystemManager::REQUIREMENT_ERROR)];
    TestSubscriber1::setTestResult($unexpected_results, StatusCheckEvent::class);
    $manager = $this->container->get('automatic_updates.status_checker');
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
    $unexpected_results = [$this->createValidationResult(SystemManager::REQUIREMENT_ERROR)];
    TestSubscriber1::setTestResult($unexpected_results, StatusCheckEvent::class);
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
    $listener = function (StatusCheckEvent $event) use (&$stage): void {
      $stage = $event->getStage();
    };
    $event_dispatcher = $this->container->get('event_dispatcher');
    $event_dispatcher->addListener(StatusCheckEvent::class, $listener);
    $this->container->get('automatic_updates.status_checker')->run();
    // By default, updates will be enabled on cron.
    $this->assertInstanceOf(CronUpdater::class, $stage);
    $this->config('automatic_updates.settings')
      ->set('cron', CronUpdater::DISABLED)
      ->save();
    $this->container->get('automatic_updates.status_checker')->run();
    $this->assertInstanceOf(Updater::class, $stage);
  }

  /**
   * Tests that stored validation results are deleted after an update.
   */
  public function testStoredResultsDeletedPostApply(): void {
    $this->setCoreVersion('9.8.0');
    $this->setReleaseMetadata([
      'drupal' => __DIR__ . '/../../../../package_manager/tests/fixtures/release-history/drupal.9.8.1-security.xml',
    ]);
    $this->container->get('module_installer')->install(['automatic_updates']);

    // The status checker should raise a warning, so that the update is not
    // blocked or aborted.
    $results = [$this->createValidationResult(SystemManager::REQUIREMENT_WARNING)];
    TestSubscriber1::setTestResult($results, StatusCheckEvent::class);

    // Ensure that the validation manager collects the warning.
    /** @var \Drupal\automatic_updates\Validation\StatusChecker $manager */
    $manager = $this->container->get('automatic_updates.status_checker')
      ->run();
    $this->assertValidationResultsEqual($results, $manager->getResults());
    TestSubscriber1::setTestResult(NULL, StatusCheckEvent::class);
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

    // The status validation manager shouldn't have any stored results.
    $this->assertEmpty($manager->getResults());
  }

  /**
   * Tests that certain config changes clear stored results.
   */
  public function testStoredResultsClearedOnConfigChanges(): void {
    $this->container->get('module_installer')->install(['automatic_updates']);

    $results = [$this->createValidationResult(SystemManager::REQUIREMENT_ERROR)];
    TestSubscriber1::setTestResult($results, StatusCheckEvent::class);
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
