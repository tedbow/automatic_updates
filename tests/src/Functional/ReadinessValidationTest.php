<?php

namespace Drupal\Tests\automatic_updates\Functional;

use Behat\Mink\Element\NodeElement;
use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\automatic_updates_test\Datetime\TestTime;
use Drupal\automatic_updates_test\EventSubscriber\TestSubscriber1;
use Drupal\automatic_updates_test2\EventSubscriber\TestSubscriber2;
use Drupal\Core\Url;
use Drupal\package_manager_test_fixture\EventSubscriber\FixtureStager;
use Drupal\system\SystemManager;
use Drupal\Tests\automatic_updates\Traits\ValidationTestTrait;
use Drupal\Tests\Traits\Core\CronRunTrait;

/**
 * Tests readiness validation.
 *
 * @group automatic_updates
 */
class ReadinessValidationTest extends AutomaticUpdatesFunctionalTestBase {

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
   * @var \Drupal\automatic_updates_test\EventSubscriber\TestSubscriber1
   */
  protected $testChecker;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setReleaseMetadata(__DIR__ . '/../../fixtures/release-history/drupal.9.8.2.xml');
    $this->setCoreVersion('9.8.1');

    $this->reportViewerUser = $this->createUser([
      'administer site configuration',
      'access administration pages',
    ]);
    $this->checkerRunnerUser = $this->createUser([
      'administer site configuration',
      'administer software updates',
      'access administration pages',
      'access site in maintenance mode',
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
    $this->assertNoErrors();
    $this->drupalLogin($this->checkerRunnerUser);
    $this->drupalGet('admin/reports/status');
    $this->assertNoErrors(TRUE);

    // Confirm a user without the permission to run readiness checks does not
    // have a link to run the checks when the checks need to be run again.
    // @todo Change this to fake the request time in
    //   https://www.drupal.org/node/3113971.
    /** @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface $key_value */
    $key_value = $this->container->get('keyvalue.expirable')->get('automatic_updates');
    $key_value->delete('readiness_validation_last_run');
    $this->drupalLogin($this->reportViewerUser);
    $this->drupalGet('admin/reports/status');
    $this->assertNoErrors();
    $this->drupalLogin($this->checkerRunnerUser);
    $this->drupalGet('admin/reports/status');
    $this->assertNoErrors(TRUE);

    // Confirm a user with the permission to run readiness checks does have a
    // link to run the checks when the checks need to be run again.
    $this->drupalLogin($this->reportViewerUser);
    $this->drupalGet('admin/reports/status');
    $this->assertNoErrors();
    $this->drupalLogin($this->checkerRunnerUser);
    $this->drupalGet('admin/reports/status');
    $this->assertNoErrors(TRUE);
    /** @var \Drupal\package_manager\ValidationResult[] $expected_results */
    $expected_results = $this->testResults['checker_1']['1 error'];
    TestSubscriber1::setTestResult($expected_results, ReadinessCheckEvent::class);

    // Run the readiness checks.
    $this->clickLink('Run readiness checks');
    $assert->statusCodeEquals(200);
    // Confirm redirect back to status report page.
    $assert->addressEquals('/admin/reports/status');
    // Assert that when the runners are run manually the message that updates
    // will not be performed because of errors is displayed on the top of the
    // page in message.
    $assert->pageTextMatchesCount(2, '/' . preg_quote(static::$errorsExplanation) . '/');
    $this->assertErrors($expected_results, TRUE);

    // @todo Should we always show when the checks were last run and a link to
    //   run when there is an error?
    // Confirm a user without permission to run the checks sees the same error.
    $this->drupalLogin($this->reportViewerUser);
    $this->drupalGet('admin/reports/status');
    $this->assertErrors($expected_results);

    $expected_results = $this->testResults['checker_1']['1 error 1 warning'];
    TestSubscriber1::setTestResult($expected_results, ReadinessCheckEvent::class);
    $key_value->delete('readiness_validation_last_run');
    // Confirm a new message is displayed if the stored messages are deleted.
    $this->drupalGet('admin/reports/status');
    // Confirm that on the status page if there is only 1 warning or error the
    // the summaries will not be displayed.
    $this->assertErrors([$expected_results['1:error']]);
    $this->assertWarnings([$expected_results['1:warning']]);
    $assert->pageTextNotContains($expected_results['1:error']->getSummary());
    $assert->pageTextNotContains($expected_results['1:warning']->getSummary());

    $key_value->delete('readiness_validation_last_run');
    $expected_results = $this->testResults['checker_1']['2 errors 2 warnings'];
    TestSubscriber1::setTestResult($expected_results, ReadinessCheckEvent::class);
    $this->drupalGet('admin/reports/status');
    // Confirm that both messages and summaries will be displayed on status
    // report when there multiple messages.
    $this->assertErrors([$expected_results['1:errors']]);
    $this->assertWarnings([$expected_results['1:warnings']]);

    $key_value->delete('readiness_validation_last_run');
    $expected_results = $this->testResults['checker_1']['2 warnings'];
    TestSubscriber1::setTestResult($expected_results, ReadinessCheckEvent::class);
    $this->drupalGet('admin/reports/status');
    $assert->pageTextContainsOnce('Update readiness checks');
    // Confirm that warnings will display on the status report if there are no
    // errors.
    $this->assertWarnings($expected_results);

    $key_value->delete('readiness_validation_last_run');
    $expected_results = $this->testResults['checker_1']['1 warning'];
    TestSubscriber1::setTestResult($expected_results, ReadinessCheckEvent::class);
    $this->drupalGet('admin/reports/status');
    $assert->pageTextContainsOnce('Update readiness checks');
    $this->assertWarnings($expected_results);
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
    $this->assertNoErrors();
    $this->drupalGet('admin/structure');
    $assert->elementNotExists('css', $messages_section_selector);

    // Confirm a user without the permission to run readiness checks does not
    // have a link to run the checks when the checks need to be run again.
    $expected_results = $this->testResults['checker_1']['1 error'];
    TestSubscriber1::setTestResult($expected_results, ReadinessCheckEvent::class);
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
    TestSubscriber1::setTestResult($expected_results, ReadinessCheckEvent::class);
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
    TestSubscriber1::setTestResult($unexpected_results, ReadinessCheckEvent::class);
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
    TestSubscriber1::setTestResult($expected_results, ReadinessCheckEvent::class);
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
    TestSubscriber1::setTestResult($expected_results, ReadinessCheckEvent::class);
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

    // Confirm readiness messages are not displayed when cron updates are
    // disabled.
    $this->drupalGet(Url::fromRoute('update.settings'));
    $edit['automatic_updates_cron'] = 'disable';
    $this->submitForm($edit, 'Save configuration');
    $this->drupalGet('admin/structure');
    $assert->pageTextNotContains(static::$warningsExplanation);
    $assert->pageTextNotContains($expected_results[0]->getMessages()[0]);
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
    $this->assertNoErrors(TRUE);

    $expected_results = $this->testResults['checker_1']['1 error'];
    TestSubscriber2::setTestResult($expected_results, ReadinessCheckEvent::class);
    $this->container->get('module_installer')->install(['automatic_updates_test2']);
    $this->drupalGet('admin/structure');
    $assert->pageTextContainsOnce($expected_results[0]->getMessages()[0]);

    // Confirm that installing a module runs the checkers, even if the new
    // module does not provide any validators.
    $previous_results = $expected_results;
    $expected_results = $this->testResults['checker_1']['2 errors 2 warnings'];
    TestSubscriber2::setTestResult($expected_results, ReadinessCheckEvent::class);
    $this->container->get('module_installer')->install(['help']);
    // Check for messages on 'admin/structure' instead of the status report,
    // because validators will be run if needed on the status report.
    $this->drupalGet('admin/structure');
    // Confirm that new checker messages are displayed.
    $assert->pageTextNotContains($previous_results[0]->getMessages()[0]);
    $assert->pageTextNotContains($expected_results['1:errors']->getMessages()[0]);
    $assert->pageTextContainsOnce($expected_results['1:errors']->getSummary());
  }

  /**
   * Tests that checker message for an uninstalled module is not displayed.
   */
  public function testReadinessCheckerUninstall(): void {
    $assert = $this->assertSession();
    $this->drupalLogin($this->checkerRunnerUser);

    $expected_results_1 = $this->testResults['checker_1']['1 error'];
    TestSubscriber1::setTestResult($expected_results_1, ReadinessCheckEvent::class);
    $expected_results_2 = $this->testResults['checker_2']['1 error'];
    TestSubscriber2::setTestResult($expected_results_2, ReadinessCheckEvent::class);
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
   * Tests that stored validation results are deleted after an update.
   */
  public function testStoredResultsClearedAfterUpdate(): void {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();
    $this->drupalLogin($this->checkerRunnerUser);

    // The current release is 9.8.1 (see ::setUp()), so ensure we're on an older
    // version.
    $this->setCoreVersion('9.8.0');

    // Flag a validation error, which will be displayed in the messages area.
    $results = $this->testResults['checker_1']['1 error'];
    TestSubscriber1::setTestResult($results, ReadinessCheckEvent::class);
    $message = $results[0]->getMessages()[0];

    $this->container->get('module_installer')->install([
      'automatic_updates',
      'automatic_updates_test',
      'package_manager_test_fixture',
    ]);
    // Because all actual staging operations are bypassed by
    // package_manager_bypass (enabled by the parent class), disable this
    // validator because it will complain if there's no actual Composer data to
    // inspect.
    $this->disableValidators(['automatic_updates.staged_projects_validator']);

    // The error should be persistently visible, even after the checker stops
    // flagging it.
    $this->drupalGet('/admin/structure');
    $assert_session->pageTextContains($message);
    TestSubscriber1::setTestResult(NULL, ReadinessCheckEvent::class);
    $this->getSession()->reload();
    $assert_session->pageTextContains($message);

    // Do the update; we don't expect any errors or special conditions to appear
    // during it. The Update button is displayed because the form does its own
    // readiness check (without storing the results), and the checker is no
    // longer raising an error.
    $this->drupalGet('/admin/modules/automatic-update');
    FixtureStager::setFixturePath(__DIR__ . '/../../fixtures/staged/9.8.1');
    $assert_session->buttonExists('Update');
    // Ensure that the previous results are still displayed on another admin
    // page, to confirm that the updater form is not discarding the previous
    // results by doing its checks.
    $this->drupalGet('/admin/structure');
    $assert_session->pageTextContains($message);
    // Proceed with the update.
    $this->drupalGet('/admin/modules/automatic-update');
    $page->pressButton('Update');
    $this->checkForMetaRefresh();
    $this->assertUpdateReady('9.8.1');
    $page->pressButton('Continue');
    $this->checkForMetaRefresh();
    $assert_session->pageTextContains('Update complete!');

    // The warning should not be visible anymore.
    $this->drupalGet('/admin/structure');
    $assert_session->pageTextNotContains($message);
  }

  /**
   * Asserts that the readiness requirement displays no errors or warnings.
   *
   * @param bool $run_link
   *   (optional) Whether there should be a link to run the readiness checks.
   *   Defaults to FALSE.
   */
  private function assertNoErrors(bool $run_link = FALSE): void {
    $this->assertRequirement('checked', 'Your site is ready for automatic updates.', [], $run_link);
  }

  /**
   * Asserts that the displayed readiness requirement contains warnings.
   *
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The readiness check results that should be visible.
   * @param bool $run_link
   *   (optional) Whether there should be a link to run the readiness checks.
   *   Defaults to FALSE.
   */
  private function assertWarnings(array $expected_results, bool $run_link = FALSE): void {
    $this->assertRequirement('warning', static::$warningsExplanation, $expected_results, $run_link);
  }

  /**
   * Asserts that the displayed readiness requirement contains errors.
   *
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The readiness check results that should be visible.
   * @param bool $run_link
   *   (optional) Whether there should be a link to run the readiness checks.
   *   Defaults to FALSE.
   */
  private function assertErrors(array $expected_results, bool $run_link = FALSE): void {
    $this->assertRequirement('error', static::$errorsExplanation, $expected_results, $run_link);
  }

  /**
   * Asserts that the readiness requirement is correct.
   *
   * @param string $section
   *   The section of the status report in which the requirement is expected to
   *   be. Can be one of 'error', 'warning', 'checked', or 'ok'.
   * @param string $preamble
   *   The text that should appear before the result messages.
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected readiness check results, in the order we expect them to be
   *   displayed.
   * @param bool $run_link
   *   (optional) Whether there should be a link to run the readiness checks.
   *   Defaults to FALSE.
   *
   * @see \Drupal\Core\Render\Element\StatusReport::getInfo()
   */
  private function assertRequirement(string $section, string $preamble, array $expected_results, bool $run_link = FALSE): void {
    // Get the meaty part of the requirement element, and ensure that it begins
    // with the preamble, if any.
    $requirement = $this->assertSession()
      ->elementExists('css', "h3#$section ~ details.system-status-report__entry:contains('Update readiness checks') .system-status-report__entry__value");

    if ($preamble) {
      $this->assertStringStartsWith($preamble, $requirement->getText());
    }

    // Convert the expected results into strings.
    $expected_messages = [];
    foreach ($expected_results as $result) {
      $messages = $result->getMessages();
      if (count($messages) > 1) {
        $expected_messages[] = $result->getSummary();
      }
      $expected_messages = array_merge($expected_messages, $messages);
    }
    $expected_messages = array_map('strval', $expected_messages);

    // The results should appear in the given order.
    $this->assertSame($expected_messages, $this->getMessagesFromRequirement($requirement));
    // Check for the presence or absence of a link to run the checks.
    $this->assertSame($run_link, $requirement->hasLink('Run readiness checks'));
  }

  /**
   * Extracts the readiness result messages from the requirement element.
   *
   * @param \Behat\Mink\Element\NodeElement $requirement
   *   The page element containing the readiness check results.
   *
   * @return string[]
   *   The readiness result messages (including summaries), in the order they
   *   appear on the page.
   */
  private function getMessagesFromRequirement(NodeElement $requirement): array {
    $messages = [];

    // Each list item will either contain a simple string (for results with only
    // one message), or a details element with a series of messages.
    $items = $requirement->findAll('css', 'li');
    foreach ($items as $item) {
      $details = $item->find('css', 'details');

      if ($details) {
        $messages[] = $details->find('css', 'summary')->getText();
        $messages = array_merge($messages, $this->getMessagesFromRequirement($details));
      }
      else {
        $messages[] = $item->getText();
      }
    }
    return array_unique($messages);
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
