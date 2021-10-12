<?php

namespace Drupal\Tests\automatic_updates\Functional;

use Drupal\automatic_updates_test\Datetime\TestTime;
use Drupal\automatic_updates_test\ReadinessChecker\TestChecker1;
use Drupal\automatic_updates_test2\ReadinessChecker\TestChecker2;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\system\SystemManager;
use Drupal\Tests\automatic_updates\Traits\ValidationTestTrait;
use Drupal\Tests\Traits\Core\CronRunTrait;

/**
 * Tests readiness validation.
 *
 * @group automatic_updates
 */
class ReadinessValidationTest extends AutomaticUpdatesFunctionalTestBase {

  use StringTranslationTrait;
  use CronRunTrait;
  use ValidationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A user who can view the status report.
   *
   * @var \Drupal\user\Entity\User
   */
  protected $reportViewerUser;

  /**
   * A user who can view the status report and run readiness checkers.
   *
   * @var \Drupal\user\Entity\User
   */
  protected $checkerRunnerUser;

  /**
   * The test checker.
   *
   * @var \Drupal\automatic_updates_test\ReadinessChecker\TestChecker1
   */
  protected $testChecker;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setReleaseMetadata(__DIR__ . '/../../fixtures/release-history/drupal.9.8.1.xml');
    $this->setCoreVersion('9.8.1');

    $this->reportViewerUser = $this->createUser([
      'administer site configuration',
      'access administration pages',
    ]);
    $this->checkerRunnerUser = $this->createUser([
      'administer site configuration',
      'administer software updates',
      'access administration pages',
    ]);
    $this->createTestValidationResults();
    $this->drupalLogin($this->reportViewerUser);
  }

  /**
   * Tests readiness checkers on status report page.
   */
  public function testReadinessChecksStatusReport(): void {
    $assert = $this->assertSession();

    // Ensure automated_cron is disabled before installing automatic_updates. This
    // ensures we are testing that automatic_updates runs the checkers when the
    // module itself is installed and they weren't run on cron.
    $this->assertFalse($this->container->get('module_handler')->moduleExists('automated_cron'));
    $this->container->get('module_installer')->install(['automatic_updates', 'automatic_updates_test']);

    // If the site is ready for updates, the users will see the same output
    // regardless of whether the user has permission to run updates.
    $this->drupalLogin($this->reportViewerUser);
    $this->checkForUpdates();
    $this->drupalGet('admin/reports/status');
    $this->assertReadinessReportMatches('Your site is ready for automatic updates.', 'checked', FALSE);
    $this->drupalLogin($this->checkerRunnerUser);
    $this->drupalGet('admin/reports/status');
    $this->assertReadinessReportMatches('Your site is ready for automatic updates. Run readiness checks now.', 'checked', FALSE);

    // Confirm a user without the permission to run readiness checks does not
    // have a link to run the checks when the checks need to be run again.
    // @todo Change this to fake the request time in
    //   https://www.drupal.org/node/3113971.
    /** @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface $key_value */
    $key_value = $this->container->get('keyvalue.expirable')->get('automatic_updates');
    $key_value->delete('readiness_validation_last_run');
    $this->drupalLogin($this->reportViewerUser);
    $this->drupalGet('admin/reports/status');
    $this->assertReadinessReportMatches('Your site is ready for automatic updates.', 'checked', FALSE);
    $this->drupalLogin($this->checkerRunnerUser);
    $this->drupalGet('admin/reports/status');
    $this->assertReadinessReportMatches('Your site is ready for automatic updates. Run readiness checks now.', 'checked', FALSE);

    // Confirm a user with the permission to run readiness checks does have a
    // link to run the checks when the checks need to be run again.
    $this->drupalLogin($this->reportViewerUser);
    $this->drupalGet('admin/reports/status');
    $this->assertReadinessReportMatches('Your site is ready for automatic updates.', 'checked', FALSE);
    $this->drupalLogin($this->checkerRunnerUser);
    $this->drupalGet('admin/reports/status');
    $this->assertReadinessReportMatches('Your site is ready for automatic updates. Run readiness checks now.', 'checked', FALSE);
    /** @var \Drupal\automatic_updates\Validation\ValidationResult[] $expected_results */
    $expected_results = $this->testResults['checker_1']['1 error'];
    TestChecker1::setTestResult($expected_results);

    // Run the readiness checks.
    $this->clickLink('Run readiness checks');
    $assert->statusCodeEquals(200);
    // Confirm redirect back to status report page.
    $assert->addressEquals('/admin/reports/status');
    // Assert that when the runners are run manually the message that updates
    // will not be performed because of errors is displayed on the top of the
    // page in message.
    $assert->pageTextMatchesCount(2, '/' . preg_quote(static::$errorsExplanation) . '/');
    $this->assertReadinessReportMatches($expected_results[0]->getMessages()[0] . 'Run readiness checks now.', 'error', static::$errorsExplanation);

    // @todo Should we always show when the checks were last run and a link to
    //   run when there is an error?
    // Confirm a user without permission to run the checks sees the same error.
    $this->drupalLogin($this->reportViewerUser);
    $this->drupalGet('admin/reports/status');
    $this->assertReadinessReportMatches($expected_results[0]->getMessages()[0], 'error', static::$errorsExplanation);

    $expected_results = $this->testResults['checker_1']['1 error 1 warning'];
    TestChecker1::setTestResult($expected_results);
    $key_value->delete('readiness_validation_last_run');
    // Confirm a new message is displayed if the stored messages are deleted.
    $this->drupalGet('admin/reports/status');
    // Confirm that on the status page if there is only 1 warning or error the
    // the summaries will not be displayed.
    $this->assertReadinessReportMatches($expected_results['1:error']->getMessages()[0], 'error', static::$errorsExplanation);
    $this->assertReadinessReportMatches($expected_results['1:warning']->getMessages()[0], 'warning', static::$warningsExplanation);
    $assert->pageTextNotContains($expected_results['1:error']->getSummary());
    $assert->pageTextNotContains($expected_results['1:warning']->getSummary());

    $key_value->delete('readiness_validation_last_run');
    $expected_results = $this->testResults['checker_1']['2 errors 2 warnings'];
    TestChecker1::setTestResult($expected_results);
    $this->drupalGet('admin/reports/status');
    // Confirm that both messages and summaries will be displayed on status
    // report when there multiple messages.
    $this->assertReadinessReportMatches($expected_results['1:errors']->getSummary() . ' ' . implode('', $expected_results['1:errors']->getMessages()), 'error', static::$errorsExplanation);
    $this->assertReadinessReportMatches($expected_results['1:warnings']->getSummary() . ' ' . implode('', $expected_results['1:warnings']->getMessages()), 'warning', static::$warningsExplanation);

    $key_value->delete('readiness_validation_last_run');
    $expected_results = $this->testResults['checker_1']['2 warnings'];
    TestChecker1::setTestResult($expected_results);
    $this->drupalGet('admin/reports/status');
    $assert->pageTextContainsOnce('Update readiness checks');
    // Confirm that warnings will display on the status report if there are no
    // errors.
    $this->assertReadinessReportMatches($expected_results[0]->getSummary() . ' ' . implode('', $expected_results[0]->getMessages()), 'warning', static::$warningsExplanation);

    $key_value->delete('readiness_validation_last_run');
    $expected_results = $this->testResults['checker_1']['1 warning'];
    TestChecker1::setTestResult($expected_results);
    $this->drupalGet('admin/reports/status');
    $assert->pageTextContainsOnce('Update readiness checks');
    $this->assertReadinessReportMatches($expected_results[0]->getMessages()[0], 'warning', static::$warningsExplanation);
  }

  /**
   * Tests readiness checkers results on admin pages..
   */
  public function testReadinessChecksAdminPages(): void {
    $assert = $this->assertSession();
    $messages_section_selector = '[data-drupal-messages]';

    // Ensure automated_cron is disabled before installing automatic_updates. This
    // ensures we are testing that automatic_updates runs the checkers when the
    // module itself is installed and they weren't run on cron.
    $this->assertFalse($this->container->get('module_handler')->moduleExists('automated_cron'));
    $this->container->get('module_installer')->install(['automatic_updates', 'automatic_updates_test']);

    // If site is ready for updates no message will be displayed on admin pages.
    $this->drupalLogin($this->reportViewerUser);
    $this->drupalGet('admin/reports/status');
    $this->assertReadinessReportMatches('Your site is ready for automatic updates.', 'checked', FALSE);
    $this->drupalGet('admin/structure');
    $assert->elementNotExists('css', $messages_section_selector);

    // Confirm a user without the permission to run readiness checks does not
    // have a link to run the checks when the checks need to be run again.
    $expected_results = $this->testResults['checker_1']['1 error'];
    TestChecker1::setTestResult($expected_results);
    // @todo Change this to use ::delayRequestTime() to simulate running cron
    //   after a 24 wait instead of directly deleting 'readiness_validation_last_run'
    //   https://www.drupal.org/node/3113971.
    /** @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface $key_value */
    $key_value = $this->container->get('keyvalue.expirable')->get('automatic_updates');
    $key_value->delete('readiness_validation_last_run');
    // A user without the permission to run the checkers will not see a message
    // on other pages if the checkers need to be run again.
    $this->drupalGet('admin/structure');
    $assert->elementNotExists('css', $messages_section_selector);

    // Confirm that a user with the correct permission can also run the checkers
    // on another admin page.
    $this->drupalLogin($this->checkerRunnerUser);
    $this->drupalGet('admin/structure');
    $assert->elementExists('css', $messages_section_selector);
    $assert->pageTextContainsOnce('Your site has not recently run an update readiness check. Run readiness checks now.');
    $this->clickLink('Run readiness checks now.');
    $assert->addressEquals('admin/structure');
    $assert->pageTextContainsOnce($expected_results[0]->getMessages()[0]);

    $expected_results = $this->testResults['checker_1']['1 error 1 warning'];
    TestChecker1::setTestResult($expected_results);
    // Confirm a new message is displayed if the cron is run after an hour.
    $this->delayRequestTime();
    $this->cronRun();
    $this->drupalGet('admin/structure');
    $assert->pageTextContainsOnce(static::$errorsExplanation);
    // Confirm on admin pages that a single error will be displayed instead of a
    // summary.
    $this->assertSame(SystemManager::REQUIREMENT_ERROR, $expected_results['1:error']->getSeverity());
    $assert->pageTextContainsOnce($expected_results['1:error']->getMessages()[0]);
    $assert->pageTextNotContains($expected_results['1:error']->getSummary());
    // Warnings are not displayed on admin pages if there are any errors.
    $this->assertSame(SystemManager::REQUIREMENT_WARNING, $expected_results['1:warning']->getSeverity());
    $assert->pageTextNotContains($expected_results['1:warning']->getMessages()[0]);
    $assert->pageTextNotContains($expected_results['1:warning']->getSummary());

    // Confirm that if cron runs less than hour after it previously ran it will
    // not run the checkers again.
    $unexpected_results = $this->testResults['checker_1']['2 errors 2 warnings'];
    TestChecker1::setTestResult($unexpected_results);
    $this->delayRequestTime(30);
    $this->cronRun();
    $this->drupalGet('admin/structure');
    $assert->pageTextNotContains($unexpected_results['1:errors']->getSummary());
    $assert->pageTextContainsOnce($expected_results['1:error']->getMessages()[0]);
    $assert->pageTextNotContains($unexpected_results['1:warnings']->getSummary());
    $assert->pageTextNotContains($expected_results['1:warning']->getMessages()[0]);

    // Confirm that is if cron is run over an hour after the checkers were
    // previously run the checkers will be run again.
    $this->delayRequestTime(31);
    $this->cronRun();
    $expected_results = $unexpected_results;
    $this->drupalGet('admin/structure');
    // Confirm on admin pages only the error summary will be displayed if there
    // is more than 1 error.
    $this->assertSame(SystemManager::REQUIREMENT_ERROR, $expected_results['1:errors']->getSeverity());
    $assert->pageTextNotContains($expected_results['1:errors']->getMessages()[0]);
    $assert->pageTextNotContains($expected_results['1:errors']->getMessages()[1]);
    $assert->pageTextContainsOnce($expected_results['1:errors']->getSummary());
    $assert->pageTextContainsOnce(static::$errorsExplanation);
    // Warnings are not displayed on admin pages if there are any errors.
    $this->assertSame(SystemManager::REQUIREMENT_WARNING, $expected_results['1:warnings']->getSeverity());
    $assert->pageTextNotContains($expected_results['1:warnings']->getMessages()[0]);
    $assert->pageTextNotContains($expected_results['1:warnings']->getMessages()[1]);
    $assert->pageTextNotContains($expected_results['1:warnings']->getSummary());

    $expected_results = $this->testResults['checker_1']['2 warnings'];
    TestChecker1::setTestResult($expected_results);
    $this->delayRequestTime();
    $this->cronRun();
    $this->drupalGet('admin/structure');
    // Confirm that the warnings summary is displayed on admin pages if there
    // are no errors.
    $assert->pageTextNotContains(static::$errorsExplanation);
    $this->assertSame(SystemManager::REQUIREMENT_WARNING, $expected_results[0]->getSeverity());
    $assert->pageTextNotContains($expected_results[0]->getMessages()[0]);
    $assert->pageTextNotContains($expected_results[0]->getMessages()[1]);
    $assert->pageTextContainsOnce(static::$warningsExplanation);
    $assert->pageTextContainsOnce($expected_results[0]->getSummary());

    $expected_results = $this->testResults['checker_1']['1 warning'];
    TestChecker1::setTestResult($expected_results);
    $this->delayRequestTime();
    $this->cronRun();
    $this->drupalGet('admin/structure');
    $assert->pageTextNotContains(static::$errorsExplanation);
    // Confirm that a single warning is displayed and not the summary on admin
    // pages if there is only 1 warning and there are no errors.
    $this->assertSame(SystemManager::REQUIREMENT_WARNING, $expected_results[0]->getSeverity());
    $assert->pageTextContainsOnce(static::$warningsExplanation);
    $assert->pageTextContainsOnce($expected_results[0]->getMessages()[0]);
    $assert->pageTextNotContains($expected_results[0]->getSummary());
  }

  /**
   * Tests installing a module with a checker before installing automatic_updates.
   */
  public function testReadinessCheckAfterInstall(): void {
    $assert = $this->assertSession();
    $this->drupalLogin($this->checkerRunnerUser);

    $this->drupalGet('admin/reports/status');
    $assert->pageTextNotContains('Update readiness checks');

    // We have to install the automatic_updates_test module because it provides
    // the functionality to retrieve our fake release history metadata.
    $this->container->get('module_installer')->install(['automatic_updates', 'automatic_updates_test']);
    $this->drupalGet('admin/reports/status');
    $this->assertReadinessReportMatches('Your site is ready for automatic updates. Run readiness checks now.', 'checked');

    $expected_results = $this->testResults['checker_1']['1 error'];
    TestChecker2::setTestResult($expected_results);
    $this->container->get('module_installer')->install(['automatic_updates_test2']);
    $this->drupalGet('admin/structure');
    $assert->pageTextContainsOnce($expected_results[0]->getMessages()[0]);

    // Confirm that installing a module that does not provide a new checker does
    // not run the checkers on install.
    $unexpected_results = $this->testResults['checker_1']['2 errors 2 warnings'];
    TestChecker2::setTestResult($unexpected_results);
    $this->container->get('module_installer')->install(['help']);
    // Check for message on 'admin/structure' instead of the status report
    // because checkers will be run if needed on the status report.
    $this->drupalGet('admin/structure');
    // Confirm that new checker message is not displayed because the checker was
    // not run again.
    $assert->pageTextContainsOnce($expected_results[0]->getMessages()[0]);
    $assert->pageTextNotContains($unexpected_results['1:errors']->getMessages()[0]);
    $assert->pageTextNotContains($unexpected_results['1:errors']->getSummary());
  }

  /**
   * Tests that checker message for an uninstalled module is not displayed.
   */
  public function testReadinessCheckerUninstall(): void {
    $assert = $this->assertSession();
    $this->drupalLogin($this->checkerRunnerUser);

    $expected_results_1 = $this->testResults['checker_1']['1 error'];
    TestChecker1::setTestResult($expected_results_1);
    $expected_results_2 = $this->testResults['checker_2']['1 error'];
    TestChecker2::setTestResult($expected_results_2);
    $this->container->get('module_installer')->install([
      'automatic_updates',
      'automatic_updates_test',
      'automatic_updates_test2',
    ]);
    // Check for message on 'admin/structure' instead of the status report
    // because checkers will be run if needed on the status report.
    $this->drupalGet('admin/structure');
    $assert->pageTextContainsOnce($expected_results_1[0]->getMessages()[0]);
    $assert->pageTextContainsOnce($expected_results_2[0]->getMessages()[0]);

    // Confirm that when on of the module is uninstalled the other module's
    // checker result is still displayed.
    $this->container->get('module_installer')->uninstall(['automatic_updates_test2']);
    $this->drupalGet('admin/structure');
    $assert->pageTextNotContains($expected_results_2[0]->getMessages()[0]);
    $assert->pageTextContainsOnce($expected_results_1[0]->getMessages()[0]);

    // Confirm that when on of the module is uninstalled the other module's
    // checker result is still displayed.
    $this->container->get('module_installer')->uninstall(['automatic_updates_test']);
    $this->drupalGet('admin/structure');
    $assert->pageTextNotContains($expected_results_2[0]->getMessages()[0]);
    $assert->pageTextNotContains($expected_results_1[0]->getMessages()[0]);
  }

  /**
   * Asserts status report readiness report item matches a format.
   *
   * @param string $format
   *   The string to match.
   * @param string $section
   *   The section of the status report in which the string should appear.
   * @param string $message_prefix
   *   The prefix for before the string.
   */
  private function assertReadinessReportMatches(string $format, string $section = 'error', string $message_prefix = ''): void {
    $format = 'Update readiness checks ' . ($message_prefix ? "$message_prefix " : '') . $format;

    $text = $this->getSession()->getPage()->find(
      'css',
      "h3#$section ~ details.system-status-report__entry:contains('Update readiness checks')"
    )->getText();
    $this->assertStringMatchesFormat($format, $text);
  }

  /**
   * Delays the request for the test.
   *
   * @param int $minutes
   *   The number of minutes to delay request time. Defaults to 61 minutes.
   */
  private function delayRequestTime(int $minutes = 61): void {
    static $total_delay = 0;
    $total_delay += $minutes;
    TestTime::setFakeTimeByOffset("+$total_delay minutes");
  }

}
