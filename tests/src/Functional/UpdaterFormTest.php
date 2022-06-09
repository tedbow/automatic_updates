<?php

namespace Drupal\Tests\automatic_updates\Functional;

use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\automatic_updates_test\Datetime\TestTime;
use Drupal\package_manager\Event\PostRequireEvent;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\ValidationResult;
use Drupal\automatic_updates_test\EventSubscriber\TestSubscriber1;
use Drupal\package_manager_test_fixture\EventSubscriber\FixtureStager;
use Drupal\Tests\automatic_updates\Traits\ValidationTestTrait;
use Drupal\Tests\package_manager\Traits\PackageManagerBypassTestTrait;

/**
 * @covers \Drupal\automatic_updates\Form\UpdaterForm
 *
 * @group automatic_updates
 */
class UpdaterFormTest extends AutomaticUpdatesFunctionalTestBase {

  use PackageManagerBypassTestTrait;
  use ValidationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'automatic_updates',
    'automatic_updates_test',
    'package_manager_test_fixture',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // In this test class, all actual staging operations are bypassed by
    // package_manager_bypass, which means these validators will complain
    // because there is no actual Composer data for them to inspect.
    $this->disableValidators[] = 'automatic_updates.staged_projects_validator';
    $this->disableValidators[] = 'automatic_updates.validator.scaffold_file_permissions';

    parent::setUp();

    $this->setReleaseMetadata(__DIR__ . '/../../fixtures/release-history/drupal.9.8.1-security.xml');
    $permissions = [
      'administer site configuration',
      'administer software updates',
      'access administration pages',
      'access site in maintenance mode',
      'administer modules',
      'access site reports',
      'view update notifications',
    ];
    // BEGIN: DELETE FROM CORE MERGE REQUEST
    // Check for permission that was added in Drupal core 9.4.x.
    $available_permissions = array_keys($this->container->get('user.permissions')->getPermissions());
    if (!in_array('view update notifications', $available_permissions)) {
      array_pop($permissions);
    }
    // END: DELETE FROM CORE MERGE REQUEST
    $user = $this->createUser($permissions);
    $this->drupalLogin($user);
    $this->checkForUpdates();
  }

  /**
   * Data provider for URLs to the update form.
   *
   * @return string[][]
   *   Test case parameters.
   */
  public function providerUpdateFormReferringUrl(): array {
    return [
      'Modules page' => ['/admin/modules/automatic-update'],
      'Reports page' => ['/admin/reports/updates/automatic-update'],
    ];
  }

  /**
   * Data provider for testTableLooksCorrect().
   *
   * @return string[][]
   *   Test case parameters.
   */
  public function providerTableLooksCorrect(): array {
    return [
      'Modules page' => ['modules'],
      'Reports page' => ['reports'],
    ];
  }

  /**
   * Tests that the form doesn't display any buttons if Drupal is up-to-date.
   *
   * @todo Mark this test as skipped if the web server is PHP's built-in, single
   *   threaded server.
   *
   * @param string $update_form_url
   *   The URL of the update form to visit.
   *
   * @dataProvider providerUpdateFormReferringUrl
   */
  public function testFormNotDisplayedIfAlreadyCurrent(string $update_form_url): void {
    $this->setCoreVersion('9.8.1');
    $this->checkForUpdates();

    $this->drupalGet($update_form_url);

    $assert_session = $this->assertSession();
    $assert_session->pageTextContains('No update available');
    $assert_session->buttonNotExists('Update');
  }

  /**
   * Tests that available updates are rendered correctly in a table.
   *
   * @param string $access_page
   *   The page from which the update form should be visited.
   *   Can be one of 'modules' to visit via the module list, or 'reports' to
   *   visit via the administrative reports page.
   *
   * @dataProvider providerTableLooksCorrect
   */
  public function testTableLooksCorrect(string $access_page): void {
    $this->drupalPlaceBlock('local_tasks_block', ['primary' => TRUE]);
    $assert_session = $this->assertSession();
    $this->setCoreVersion('9.8.0');
    $this->checkForUpdates();

    // Navigate to the automatic updates form.
    $this->drupalGet('/admin');
    if ($access_page === 'modules') {
      $this->clickLink('Extend');
      $assert_session->pageTextContainsOnce('There is a security update available for your version of Drupal.');
    }
    else {
      $this->clickLink('Reports');
      $assert_session->pageTextContainsOnce('There is a security update available for your version of Drupal.');
      $this->clickLink('Available updates');
    }
    $this->clickLink('Update');
    $assert_session->pageTextNotContains('There is a security update available for your version of Drupal.');
    $cells = $assert_session->elementExists('css', '#edit-projects .update-update-security')
      ->findAll('css', 'td');
    $this->assertCount(3, $cells);
    $assert_session->elementExists('named', ['link', 'Drupal'], $cells[0]);
    $this->assertSame('9.8.0', $cells[1]->getText());
    $this->assertSame('9.8.1 (Release notes)', $cells[2]->getText());
    $release_notes = $assert_session->elementExists('named', ['link', 'Release notes'], $cells[2]);
    $this->assertSame('Release notes for Drupal', $release_notes->getAttribute('title'));
    $assert_session->buttonExists('Update');
    $this->assertUpdateStagedTimes(0);
  }

  /**
   * Tests handling of errors and warnings during the update process.
   */
  public function testUpdateErrors(): void {
    $session = $this->getSession();
    $assert_session = $this->assertSession();
    $page = $session->getPage();

    $cached_message = $this->setAndAssertCachedMessage();
    // Ensure that the fake error is cached.
    $session->reload();
    $assert_session->pageTextContainsOnce($cached_message);

    $this->setCoreVersion('9.8.0');
    $this->checkForUpdates();

    // Set up a new fake error. Use an error with multiple messages so we can
    // ensure that they're all displayed, along with their summary.
    $this->createTestValidationResults();
    $expected_results = [$this->testResults['checker_1']['2 errors 2 warnings']['1:errors']];
    TestSubscriber1::setTestResult($expected_results, ReadinessCheckEvent::class);

    // If a validator raises an error during readiness checking, the form should
    // not have a submit button.
    $this->drupalGet('/admin/modules/automatic-update');
    $assert_session->buttonNotExists('Update');
    // Since this is an administrative page, the error message should be visible
    // thanks to automatic_updates_page_top(). The readiness checks were re-run
    // during the form build, which means the new error should be cached and
    // displayed instead of the previously cached error.
    $assert_session->pageTextContainsOnce((string) $expected_results[0]->getMessages()[0]);
    $assert_session->pageTextContainsOnce((string) $expected_results[0]->getMessages()[1]);
    $assert_session->pageTextContainsOnce((string) $expected_results[0]->getSummary());
    $assert_session->pageTextContainsOnce(static::$errorsExplanation);
    $assert_session->pageTextNotContains(static::$warningsExplanation);
    $assert_session->pageTextNotContains($cached_message);
    TestSubscriber1::setTestResult(NULL, ReadinessCheckEvent::class);

    // Make the validator throw an exception during pre-create.
    $error = new \Exception('The update exploded.');
    TestSubscriber1::setException($error, PreCreateEvent::class);
    $session->reload();
    $assert_session->pageTextNotContains(static::$errorsExplanation);
    $assert_session->pageTextNotContains(static::$warningsExplanation);
    $assert_session->pageTextNotContains($cached_message);
    $page->pressButton('Update');
    $this->checkForMetaRefresh();
    $this->assertUpdateStagedTimes(0);
    $assert_session->pageTextContainsOnce('An error has occurred.');
    $page->clickLink('the error page');
    // We should see the exception message, but not the validation result's
    // messages or summary, because exceptions thrown directly by event
    // subscribers are wrapped in simple exceptions and re-thrown.
    $assert_session->pageTextContainsOnce($error->getMessage());
    $assert_session->pageTextNotContains((string) $expected_results[0]->getMessages()[0]);
    $assert_session->pageTextNotContains($expected_results[0]->getSummary());
    $assert_session->pageTextNotContains($cached_message);
    // Since the error occurred during pre-create, there should be no existing
    // update to delete.
    $assert_session->buttonNotExists('Delete existing update');

    // If a validator flags an error, but doesn't throw, the update should still
    // be halted.
    TestSubscriber1::setTestResult($expected_results, PreCreateEvent::class);
    $page->pressButton('Update');
    $this->checkForMetaRefresh();
    $this->assertUpdateStagedTimes(0);
    $assert_session->pageTextContainsOnce('An error has occurred.');
    $page->clickLink('the error page');
    $assert_session->pageTextContainsOnce($expected_results[0]->getSummary());
    $assert_session->pageTextContainsOnce((string) $expected_results[0]->getMessages()[0]);
    $assert_session->pageTextContainsOnce((string) $expected_results[0]->getMessages()[1]);
    $assert_session->pageTextNotContains($cached_message);
  }

  /**
   * Tests that updating to a different minor version isn't supported.
   *
   * @param string $update_form_url
   *   The URL of the update form to visit.
   *
   * @dataProvider providerUpdateFormReferringUrl
   */
  public function testMinorVersionUpdateNotSupported(string $update_form_url): void {
    $this->setCoreVersion('9.7.1');
    $this->checkForUpdates();

    $this->drupalGet($update_form_url);

    $assert_session = $this->assertSession();
    $assert_session->pageTextContains('Updates were found, but they must be performed manually. See the list of available updates for more information.');
    $this->clickLink('the list of available updates');
    $assert_session->elementExists('css', 'table.update');
    $assert_session->buttonNotExists('Update');
  }

  /**
   * Tests deleting an existing update.
   */
  public function testDeleteExistingUpdate(): void {
    $conflict_message = 'Cannot begin an update because another Composer operation is currently in progress.';
    $cancelled_message = 'The update was successfully cancelled.';

    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();
    $this->setCoreVersion('9.8.0');
    $this->checkForUpdates();

    $this->drupalGet('/admin/modules/automatic-update');
    FixtureStager::setFixturePath(__DIR__ . '/../../fixtures/staged/9.8.1');
    $page->pressButton('Update');
    $this->checkForMetaRefresh();
    $this->assertUpdateStagedTimes(1);

    // Confirm we are on the confirmation page.
    $this->assertUpdateReady('9.8.1');
    $assert_session->buttonExists('Continue');

    // If we try to return to the start page, we should be redirected back to
    // the confirmation page.
    $this->drupalGet('/admin/modules/automatic-update');
    $this->assertUpdateReady('9.8.1');

    // Delete the existing update.
    $page->pressButton('Cancel update');
    $assert_session->addressEquals('/admin/reports/updates/automatic-update');
    $assert_session->pageTextContains($cancelled_message);
    $assert_session->pageTextNotContains($conflict_message);
    // Ensure we can start another update after deleting the existing one.
    $page->pressButton('Update');
    $this->checkForMetaRefresh();

    // Confirm we are on the confirmation page.
    $this->assertUpdateReady('9.8.1');
    $this->assertUpdateStagedTimes(2);
    $assert_session->buttonExists('Continue');

    // Log in as another administrative user and ensure that we cannot begin an
    // update because the previous session already started one.
    $account = $this->createUser([], NULL, TRUE);
    $this->drupalLogin($account);
    $this->drupalGet('/admin/reports/updates/automatic-update');
    $assert_session->pageTextContains($conflict_message);
    $assert_session->buttonNotExists('Update');
    // We should be able to delete the previous update, then start a new one.
    $page->pressButton('Delete existing update');
    $assert_session->pageTextContains('Staged update deleted');
    $assert_session->pageTextNotContains($conflict_message);
    $page->pressButton('Update');
    $this->checkForMetaRefresh();
    $this->assertUpdateReady('9.8.1');

    // Stop execution during pre-apply. This should make Package Manager think
    // the staged changes are being applied and raise an error if we try to
    // cancel the update.
    TestSubscriber1::setExit(PreApplyEvent::class);
    $page->pressButton('Continue');
    $this->checkForMetaRefresh();
    $page->clickLink('the error page');
    $page->pressButton('Cancel update');
    // The exception should have been caught and displayed in the messages area.
    $assert_session->statusCodeEquals(200);
    $destroy_error = 'Cannot destroy the staging area while it is being applied to the active directory.';
    $assert_session->pageTextContains($destroy_error);
    $assert_session->pageTextNotContains($cancelled_message);

    // We should get the same error if we log in as another user and try to
    // delete the staged update.
    $user = $this->createUser([
      'administer software updates',
      'access site in maintenance mode',
    ]);
    $this->drupalLogin($user);
    $this->drupalGet('/admin/reports/updates/automatic-update');
    $assert_session->pageTextContains($conflict_message);
    $page->pressButton('Delete existing update');
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains($destroy_error);
    $assert_session->pageTextNotContains('Staged update deleted');

    // Two hours later, Package Manager should consider the stage to be stale,
    // allowing the staged update to be deleted.
    TestTime::setFakeTimeByOffset('+2 hours');
    $this->getSession()->reload();
    $assert_session->pageTextContains($conflict_message);
    $page->pressButton('Delete existing update');
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Staged update deleted');

    // If a legitimate error is raised during pre-apply, we should be able to
    // delete the staged update right away.
    $this->createTestValidationResults();
    $results = $this->testResults['checker_1']['1 error'];
    TestSubscriber1::setTestResult($results, PreApplyEvent::class);
    $page->pressButton('Update');
    $this->checkForMetaRefresh();
    $this->assertUpdateReady('9.8.1');
    $page->pressButton('Continue');
    $this->checkForMetaRefresh();
    $page->clickLink('the error page');
    $page->pressButton('Cancel update');
    $assert_session->pageTextContains($cancelled_message);
  }

  /**
   * Data provider for testStagedDatabaseUpdates().
   *
   * @return bool[][]
   *   The test cases.
   */
  public function providerStagedDatabaseUpdates() {
    return [
      'maintenance mode on' => [TRUE],
      'maintenance mode off' => [FALSE],
    ];
  }

  /**
   * Tests the update form when staged modules have database updates.
   *
   * @param bool $maintenance_mode_on
   *   Whether the site should be in maintenance mode at the beginning of the
   *   update process.
   *
   * @dataProvider providerStagedDatabaseUpdates
   */
  public function testStagedDatabaseUpdates(bool $maintenance_mode_on): void {
    $this->setCoreVersion('9.8.0');
    $this->checkForUpdates();
    $cached_message = $this->setAndAssertCachedMessage();

    $state = $this->container->get('state');
    $state->set('system.maintenance_mode', $maintenance_mode_on);

    // Flag a warning, which will not block the update but should be displayed
    // on the updater form.
    $this->createTestValidationResults();
    $expected_results = $this->testResults['checker_1']['1 warning'];
    TestSubscriber1::setTestResult($expected_results, ReadinessCheckEvent::class);
    $messages = reset($expected_results)->getMessages();

    $page = $this->getSession()->getPage();
    $this->drupalGet('/admin/modules/automatic-update');
    FixtureStager::setFixturePath(__DIR__ . '/../../fixtures/staged/9.8.1');
    // The warning should be visible.
    $assert_session = $this->assertSession();
    $assert_session->pageTextContains(reset($messages));
    $assert_session->pageTextNotContains($cached_message);
    $page->pressButton('Update');
    $this->checkForMetaRefresh();
    $this->assertUpdateStagedTimes(1);
    $this->assertUpdateReady('9.8.1');
    // Simulate a staged database update in the automatic_updates_test module.
    // We must do this after the update has started, because the pending updates
    // validator will prevent an update from starting.
    $state->set('automatic_updates_test.new_update', TRUE);
    // The warning from the updater form should be not be repeated, but we
    // should see a warning about pending database updates, and once the staged
    // changes have been applied, we should be redirected to update.php, where
    // neither warning should be visible.
    $assert_session->pageTextNotContains(reset($messages));
    $possible_update_message = 'Possible database updates were detected in the following modules; you may be redirected to the database update page in order to complete the update process.';
    $assert_session->pageTextContains($possible_update_message);
    $assert_session->pageTextContains('System');
    if ($maintenance_mode_on === TRUE) {
      $assert_session->fieldNotExists('maintenance_mode');
    }
    else {
      $assert_session->checkboxChecked('maintenance_mode');
    }
    $assert_session->pageTextNotContains($cached_message);
    $page->pressButton('Continue');
    $this->checkForMetaRefresh();
    // Confirm that the site was in maintenance before the update was applied.
    // @see \Drupal\package_manager_test_validation\EventSubscriber\TestSubscriber::handleEvent()
    $this->assertTrue($state->get(PreApplyEvent::class . '.system.maintenance_mode'));
    // Confirm the site remains in maintenance more when redirected to
    // update.php.
    $this->assertTrue($state->get('system.maintenance_mode'));
    $assert_session->addressEquals('/update.php');
    $assert_session->pageTextNotContains($cached_message);
    $assert_session->pageTextNotContains(reset($messages));
    $assert_session->pageTextNotContains($possible_update_message);
    $assert_session->pageTextContainsOnce('Please apply database updates to complete the update process.');
    $this->assertTrue($state->get('system.maintenance_mode'));
    $page->clickLink('Continue');
    // @see automatic_updates_update_9001()
    $assert_session->pageTextContains('Dynamic automatic_updates_update_9001');
    $page->clickLink('Apply pending updates');
    $this->checkForMetaRefresh();
    $assert_session->pageTextContains('Updates were attempted.');
    // Confirm the site was returned to the original maintenance module state.
    $this->assertSame($state->get('system.maintenance_mode'), $maintenance_mode_on);
    $assert_session->pageTextNotContains($cached_message);
  }

  /**
   * Data provider for testSuccessfulUpdate().
   *
   * @return string[][]
   *   Test case parameters.
   */
  public function providerSuccessfulUpdate(): array {
    return [
      'Modules page, maintenance mode on' => [
        '/admin/modules/automatic-update',
        TRUE,
      ],
      'Modules page, maintenance mode off' => [
        '/admin/modules/automatic-update',
        FALSE,
      ],
      'Reports page, maintenance mode on' => [
        '/admin/reports/updates/automatic-update',
        TRUE,
      ],
      'Reports page, maintenance mode off' => [
        '/admin/reports/updates/automatic-update',
        FALSE,
      ],
    ];
  }

  /**
   * Tests an update that has no errors or special conditions.
   *
   * @param string $update_form_url
   *   The URL of the update form to visit.
   * @param bool $maintenance_mode_on
   *   Whether maintenance should be on at the beginning of the update.
   *
   * @dataProvider providerSuccessfulUpdate
   */
  public function testSuccessfulUpdate(string $update_form_url, bool $maintenance_mode_on): void {
    $assert_session = $this->assertSession();
    $this->setCoreVersion('9.8.0');
    $this->checkForUpdates();
    $state = $this->container->get('state');
    $state->set('system.maintenance_mode', $maintenance_mode_on);
    $page = $this->getSession()->getPage();
    $cached_message = $this->setAndAssertCachedMessage();

    $this->drupalGet($update_form_url);
    FixtureStager::setFixturePath(__DIR__ . '/../../fixtures/staged/9.8.1');
    $assert_session->pageTextNotContains($cached_message);
    $page->pressButton('Update');
    $this->checkForMetaRefresh();
    $this->assertUpdateStagedTimes(1);
    $this->assertUpdateReady('9.8.1');
    // Confirm that the site was put into maintenance mode if needed.
    $this->assertSame($state->get('system.maintenance_mode'), $maintenance_mode_on);
    $page->pressButton('Continue');
    $this->checkForMetaRefresh();
    $assert_session->addressEquals('/admin/reports/updates');
    $assert_session->pageTextNotContains($cached_message);
    // Confirm that the site was in maintenance before the update was applied.
    // @see \Drupal\package_manager_test_validation\EventSubscriber\TestSubscriber::handleEvent()
    $this->assertTrue($state->get(PreApplyEvent::class . '.system.maintenance_mode'));
    $assert_session->pageTextContainsOnce('Update complete!');
    // Confirm the site was returned to the original maintenance mode state.
    $this->assertSame($state->get('system.maintenance_mode'), $maintenance_mode_on);
  }

  /**
   * Tests what happens when a staged update is deleted without being destroyed.
   */
  public function testStagedUpdateDeletedImproperly(): void {
    $this->setCoreVersion('9.8.0');
    $this->checkForUpdates();

    $page = $this->getSession()->getPage();
    $this->drupalGet('/admin/modules/automatic-update');
    FixtureStager::setFixturePath(__DIR__ . '/../../fixtures/staged/9.8.1');
    $page->pressButton('Update');
    $this->checkForMetaRefresh();
    $this->assertUpdateStagedTimes(1);
    $this->assertUpdateReady('9.8.1');
    // Confirm if the staged directory is deleted without using destroy(), then
    // an error message will be displayed on the page.
    // @see \Drupal\package_manager\Stage::getStagingRoot()
    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $file_system = $this->container->get('file_system');
    $dir = $file_system->getTempDirectory() . '/.package_manager' . $this->config('system.site')->get('uuid');
    $this->assertDirectoryExists($dir);
    $file_system->deleteRecursive($dir);
    $this->getSession()->reload();
    $assert_session = $this->assertSession();
    $error_message = 'There was an error loading the pending update. Press the Cancel update button to start over.';
    $assert_session->pageTextContainsOnce($error_message);
    // We should be able to start over without any problems, and the error
    // message should not be seen on the updater form.
    $page->pressButton('Cancel update');
    $assert_session->addressEquals('/admin/reports/updates/automatic-update');
    $assert_session->pageTextNotContains($error_message);
    $assert_session->pageTextContains('The update was successfully cancelled.');
    $assert_session->buttonExists('Update');
  }

  /**
   * Tests that the update stage is destroyed if an error occurs during require.
   */
  public function testStageDestroyedOnError(): void {
    $session = $this->getSession();
    $assert_session = $this->assertSession();
    $page = $session->getPage();
    $this->setCoreVersion('9.8.0');
    $this->checkForUpdates();

    $this->drupalGet('/admin/modules/automatic-update');
    $error = new \Exception('Some Exception');
    TestSubscriber1::setException($error, PostRequireEvent::class);
    $assert_session->pageTextNotContains(static::$errorsExplanation);
    $assert_session->pageTextNotContains(static::$warningsExplanation);
    $page->pressButton('Update');
    $this->checkForMetaRefresh();
    $this->assertUpdateStagedTimes(1);
    $assert_session->pageTextContainsOnce('An error has occurred.');
    $page->clickLink('the error page');
    $assert_session->addressEquals('/admin/modules/automatic-update');
    $assert_session->pageTextNotContains('Cannot begin an update because another Composer operation is currently in progress.');
    $assert_session->buttonNotExists('Delete existing update');
    $assert_session->pageTextContains('Some Exception');
    $assert_session->buttonExists('Update');
  }

  /**
   * Sets an error message, runs readiness checks, and asserts it is displayed.
   *
   * @return string
   *   The cached error check message.
   */
  private function setAndAssertCachedMessage(): string {
    // Store a readiness error, which will be cached.
    $message = "You've not experienced Shakespeare until you have read him in the original Klingon.";
    $result = ValidationResult::createError([$message]);
    TestSubscriber1::setTestResult([$result], ReadinessCheckEvent::class);
    // Run the readiness checks a visit an admin page the message will be
    // displayed.
    $this->drupalGet('/admin/reports/status');
    $this->clickLink('Run readiness checks');
    $this->drupalGet('/admin');
    $this->assertSession()->pageTextContains($message);
    // Clear the results so the only way the message could appear on the pages
    // used for the update process is if they show the cached results.
    TestSubscriber1::setTestResult(NULL, ReadinessCheckEvent::class);

    return $message;
  }

}
