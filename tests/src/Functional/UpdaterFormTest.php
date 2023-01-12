<?php

declare(strict_types = 1);

namespace Drupal\Tests\automatic_updates\Functional;

use Drupal\automatic_updates_test\Datetime\TestTime;
use Drupal\fixture_manipulator\StageFixtureManipulator;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\package_manager_test_validation\StagedDatabaseUpdateValidator;
use Drupal\package_manager\Event\PostRequireEvent;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PostApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\StatusCheckEvent;
use Drupal\package_manager\ValidationResult;
use Drupal\automatic_updates_test\EventSubscriber\TestSubscriber1;
use Drupal\package_manager_bypass\Committer;
use Drupal\package_manager_test_validation\EventSubscriber\TestSubscriber;
use Drupal\system\SystemManager;
use Drupal\Tests\automatic_updates\Traits\ValidationTestTrait;
use Drupal\Tests\package_manager\Traits\PackageManagerBypassTestTrait;

/**
 * @covers \Drupal\automatic_updates\Form\UpdaterForm
 * @group automatic_updates
 * @internal
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
    'help',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->setReleaseMetadata(__DIR__ . '/../../../package_manager/tests/fixtures/release-history/drupal.9.8.1-security.xml');
    $permissions = [
      'administer site configuration',
      'administer software updates',
      'access administration pages',
      'access site in maintenance mode',
      'administer modules',
      'access site reports',
    ];
    // Check for permission that was added in Drupal core 9.4.x.
    $available_permissions = array_keys($this->container->get('user.permissions')->getPermissions());
    if (in_array('view update notifications', $available_permissions, TRUE)) {
      array_push($permissions, 'view update notifications');
    }
    $user = $this->createUser($permissions);
    $this->drupalLogin($user);
    $this->checkForUpdates();
  }

  /**
   * Data provider for URLs to the update form.
   *
   * @return string[][]
   *   The test cases.
   */
  public function providerUpdateFormReferringUrl(): array {
    return [
      'Modules page' => ['/admin/modules/update'],
      'Reports page' => ['/admin/reports/updates/update'],
    ];
  }

  /**
   * Data provider for testTableLooksCorrect().
   *
   * @return string[][]
   *   The test cases.
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
    $this->mockActiveCoreVersion('9.8.1');
    $this->checkForUpdates();

    $this->drupalGet($update_form_url);

    $assert_session = $this->assertSession();
    $assert_session->pageTextContains('No update available');
    $this->assertNoUpdateButtons();
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
    $assert_session = $this->assertSession();

    $assert_minor_update_help = function () use ($assert_session): void {
      $assert_session->pageTextContainsOnce('The following updates are in newer minor version of Drupal. Learn more about updating to another minor version.');
      $assert_session->linkExists('Learn more about updating to another minor version.');
    };
    $assert_no_minor_update_help = function () use ($assert_session): void {
      $assert_session->pageTextNotContains('The following updates are in newer minor version of Drupal. Learn more about updating to another minor version.');
    };

    $page = $this->getSession()->getPage();
    $this->drupalPlaceBlock('local_tasks_block', ['primary' => TRUE]);
    $this->mockActiveCoreVersion('9.8.0');
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

    // Check the form when there is an update in the installed minor only.
    $assert_session->pageTextContainsOnce('Currently installed: 9.8.0 (Security update required!)');
    $this->checkReleaseTable('#edit-installed-minor', '.update-update-security', '9.8.1', TRUE, 'Latest version of Drupal 9.8 (currently installed):');
    $assert_session->elementNotExists('css', '#edit-next-minor-1');
    $assert_no_minor_update_help();

    // Check the form when there is an update in the next minor only.
    $this->config('automatic_updates.settings')->set('allow_core_minor_updates', TRUE)->save();
    $this->mockActiveCoreVersion('9.7.0');
    $page->clickLink('Check manually');
    $this->checkForMetaRefresh();
    $this->checkReleaseTable('#edit-next-minor-1', '.update-update-recommended', '9.8.1', TRUE, 'Latest version of Drupal 9.8 (next minor) (Release notes):');
    $assert_minor_update_help();
    $this->assertReleaseNotesLink(9, 8, '#edit-next-minor-1');
    $assert_session->pageTextContainsOnce('Currently installed: 9.7.0 (Not supported!)');
    $assert_session->elementNotExists('css', '#edit-installed-minor');

    // Check the form when there are updates in the current and next minors but
    // the site does not support minor updates.
    $this->config('automatic_updates.settings')->set('allow_core_minor_updates', FALSE)->save();
    $this->setReleaseMetadata(__DIR__ . '/../../../package_manager/tests/fixtures/release-history/drupal.9.8.2.xml');
    $page->clickLink('Check manually');
    $this->checkForMetaRefresh();
    $assert_session->pageTextContainsOnce('Currently installed: 9.7.0 (Update available)');
    $this->checkReleaseTable('#edit-installed-minor', '.update-update-recommended', '9.7.1', TRUE, 'Latest version of Drupal 9.7 (currently installed):');
    $assert_session->elementNotExists('css', '#edit-next-minor-1');
    $assert_no_minor_update_help();

    // Check that if minor updates are enabled the update in the next minor will
    // be visible.
    $this->config('automatic_updates.settings')->set('allow_core_minor_updates', TRUE)->save();
    $this->getSession()->reload();
    $this->checkReleaseTable('#edit-installed-minor', '.update-update-recommended', '9.7.1', TRUE, 'Latest version of Drupal 9.7 (currently installed):');
    $this->checkReleaseTable('#edit-next-minor-1', '.update-update-optional', '9.8.2', FALSE, 'Latest version of Drupal 9.8 (next minor) (Release notes):');
    $this->assertReleaseNotesLink(9, 8, '#edit-next-minor-1');
    $assert_minor_update_help();

    $this->mockActiveCoreVersion('9.7.1');
    $page->clickLink('Check manually');
    $this->checkForMetaRefresh();
    $assert_session->pageTextContainsOnce('Currently installed: 9.7.1 (Update available)');
    $assert_session->elementNotExists('css', '#edit-installed-minor');
    $this->checkReleaseTable('#edit-next-minor-1', '.update-update-recommended', '9.8.2', FALSE, 'Latest version of Drupal 9.8 (next minor) (Release notes):');
    $this->assertReleaseNotesLink(9, 8, '#edit-next-minor-1');
    $assert_minor_update_help();

    // Check that if minor updates are enabled then updates in the next minors
    // are visible.
    $this->config('automatic_updates.settings')->set('allow_core_minor_updates', TRUE)->save();
    $this->setReleaseMetadata(__DIR__ . '/../../../package_manager/tests/fixtures/release-history/drupal.10.0.0.xml');
    $this->mockActiveCoreVersion('9.5.0');
    $page->clickLink('Check manually');
    $this->checkForMetaRefresh();
    $assert_session->pageTextNotContains('10.0.0');
    $assert_session->pageTextContainsOnce('Currently installed: 9.5.0 (Update available)');
    $this->checkReleaseTable('#edit-installed-minor', '.update-update-recommended', '9.5.1', TRUE, 'Latest version of Drupal 9.5 (currently installed):');
    $this->checkReleaseTable('#edit-next-minor-1', '.update-update-optional', '9.6.1', FALSE, 'Latest version of Drupal 9.6 (next minor) (Release notes):');
    $this->assertReleaseNotesLink(9, 6, '#edit-next-minor-1');
    $this->checkReleaseTable('#edit-next-minor-2', '.update-update-optional', '9.7.2', FALSE, 'Latest version of Drupal 9.7 (next minor) (Release notes):');
    $this->assertReleaseNotesLink(9, 7, '#edit-next-minor-2');
    $assert_minor_update_help();

    // Check that if installed version is unsupported and minor updates are
    // enabled then updates in the next minors are visible.
    $this->mockActiveCoreVersion('9.4.0');
    $page->clickLink('Check manually');
    $this->checkForMetaRefresh();
    $assert_session->pageTextNotContains('10.0.0');
    $assert_session->pageTextContainsOnce('Currently installed: 9.4.0 (Not supported!)');
    $this->checkReleaseTable('#edit-next-minor-1', '.update-update-recommended', '9.5.1', TRUE, 'Latest version of Drupal 9.5 (next minor) (Release notes):');
    $this->assertReleaseNotesLink(9, 5, '#edit-next-minor-1');
    $this->checkReleaseTable('#edit-next-minor-2', '.update-update-recommended', '9.6.1', FALSE, 'Latest version of Drupal 9.6 (next minor) (Release notes):');
    $this->assertReleaseNotesLink(9, 6, '#edit-next-minor-2');
    $this->checkReleaseTable('#edit-next-minor-3', '.update-update-recommended', '9.7.2', FALSE, 'Latest version of Drupal 9.7 (next minor) (Release notes):');
    $this->assertReleaseNotesLink(9, 7, '#edit-next-minor-3');
    $assert_minor_update_help();

    $this->assertUpdateStagedTimes(0);

    // If the minor update help link exists, ensure it links to the right place.
    $help_link = $page->findLink('Learn more about updating to another minor version.');
    if ($help_link) {
      $this->assertStringEndsWith('#minor-update', $help_link->getAttribute('href'));
      $help_link->click();
      $assert_session->statusCodeEquals(200);
      $assert_session->responseContains('id="minor-update"');
    }
  }

  /**
   * Tests status checks are displayed when there is no update available.
   */
  public function testStatusCheckFailureWhenNoUpdateExists() {
    $assert_session = $this->assertSession();
    $this->mockActiveCoreVersion('9.8.1');
    $message = t("You've not experienced Shakespeare until you have read him in the original Klingon.");
    $result = ValidationResult::createError([$message]);
    TestSubscriber1::setTestResult([$result], StatusCheckEvent::class);
    $this->checkForUpdates();
    $this->drupalGet('/admin/reports/updates/automatic-update');
    $assert_session->pageTextContains('No update available');
    $assert_session->pageTextContains($message);
  }

  /**
   * Checks pre-releases of the next minor are available on the form.
   */
  public function testNextMinorPreRelease(): void {
    $this->setReleaseMetadata(__DIR__ . '/../../../package_manager/tests/fixtures/release-history/drupal.9.8.0-beta1.xml');
    $this->mockActiveCoreVersion('9.7.0');
    $this->config('automatic_updates.settings')
      ->set('allow_core_minor_updates', TRUE)
      ->save();
    $this->checkForUpdates();
    $this->drupalGet('/admin/reports/updates/automatic-update');
    $assert_session = $this->assertSession();
    $this->checkReleaseTable('#edit-next-minor-1', '.update-update-recommended', '9.8.0-beta1', FALSE, 'Latest version of Drupal 9.8 (next minor):');
    $assert_session->pageTextContainsOnce('Currently installed: 9.7.0 (Up to date)');
  }

  /**
   * Checks the table for a release on the form.
   *
   * @param string $container_locator
   *   The CSS locator for the element with contains the table.
   * @param string $row_class
   *   The row class for the update.
   * @param string $version
   *   The release version number.
   * @param bool $is_primary
   *   Whether update button should be a primary button.
   * @param string|null $table_caption
   *   The table caption or NULL if none expected.
   */
  private function checkReleaseTable(string $container_locator, string $row_class, string $version, bool $is_primary, ?string $table_caption = NULL): void {
    $assert_session = $this->assertSession();
    $assert_session->pageTextNotContains('There is a security update available for your version of Drupal.');
    $assert_session->linkExists('Drupal core');
    $container = $assert_session->elementExists('css', $container_locator);
    if ($table_caption) {
      $this->assertSame($table_caption, $assert_session->elementExists('css', 'caption', $container)->getText());
    }
    else {
      $assert_session->elementNotExists('css', 'caption', $container);
    }

    $cells = $assert_session->elementExists('css', $row_class, $container)
      ->findAll('css', 'td');
    $this->assertCount(2, $cells);
    $this->assertSame("$version (Release notes)", $cells[1]->getText());
    $release_notes = $assert_session->elementExists('named', ['link', 'Release notes'], $cells[1]);
    $this->assertSame("Release notes for Drupal core $version", $release_notes->getAttribute('title'));
    $button = $assert_session->buttonExists("Update to $version", $container);
    $this->assertSame($is_primary, $button->hasClass('button--primary'));
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

    $this->mockActiveCoreVersion('9.8.0');
    $this->checkForUpdates();

    // Set up a new fake error. Use an error with multiple messages so we can
    // ensure that they're all displayed, along with their summary.
    $expected_results = [$this->createValidationResult(SystemManager::REQUIREMENT_ERROR, 2)];
    TestSubscriber1::setTestResult($expected_results, StatusCheckEvent::class);

    // If a validator raises an error during status checking, the form should
    // not have a submit button.
    $this->drupalGet('/admin/modules/update');
    $this->assertNoUpdateButtons();
    // Since this is an administrative page, the error message should be visible
    // thanks to automatic_updates_page_top(). The status checks were re-run
    // during the form build, which means the new error should be cached and
    // displayed instead of the previously cached error.
    $assert_session->pageTextContainsOnce((string) $expected_results[0]->getMessages()[0]);
    $assert_session->pageTextContainsOnce((string) $expected_results[0]->getMessages()[1]);
    $assert_session->pageTextContainsOnce((string) $expected_results[0]->getSummary());
    $assert_session->pageTextContainsOnce(static::$errorsExplanation);
    $assert_session->pageTextNotContains(static::$warningsExplanation);
    $assert_session->pageTextNotContains($cached_message);
    TestSubscriber1::setTestResult(NULL, StatusCheckEvent::class);

    // Make the validator throw an exception during pre-create.
    $error = new \Exception('The update exploded.');
    TestSubscriber1::setException($error, PreCreateEvent::class);
    $session->reload();
    $assert_session->pageTextNotContains(static::$errorsExplanation);
    $assert_session->pageTextNotContains(static::$warningsExplanation);
    $assert_session->pageTextNotContains($cached_message);
    $page->pressButton('Update to 9.8.1');
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
    $page->pressButton('Update to 9.8.1');
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
   * Tests that an exception is thrown if a previous apply failed.
   */
  public function testMarkerFileFailure(): void {
    (new StageFixtureManipulator())
      ->setCorePackageVersion('9.8.1')
      ->setReadyToCommit();
    $session = $this->getSession();
    $assert_session = $this->assertSession();
    $page = $session->getPage();
    $this->mockActiveCoreVersion('9.8.0');
    $this->checkForUpdates();

    $this->drupalGet('/admin/modules/automatic-update');
    $assert_session->pageTextNotContains(static::$errorsExplanation);
    $assert_session->pageTextNotContains(static::$warningsExplanation);
    $page->pressButton('Update to 9.8.1');
    $this->checkForMetaRefresh();
    $this->assertUpdateStagedTimes(1);

    Committer::setException(new \Exception('failed at committer'));
    $page->pressButton('Continue');
    $this->checkForMetaRefresh();
    $assert_session->pageTextContainsOnce('An error has occurred.');
    $assert_session->pageTextContains("The update operation failed to apply completely. All the files necessary to run Drupal correctly and securely are probably not present. It is strongly recommended to restore your site's code and database from a backup.");
    $page->clickLink('the error page');

    $failure_message = 'Automatic updates failed to apply, and the site is in an indeterminate state. Consider restoring the code and database from a backup.';
    // We should be on the form (i.e., 200 response code), but unable to
    // continue the update.
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains($failure_message);
    $assert_session->buttonNotExists('Continue');
    // The same thing should be true if we try to start from the beginning.
    $this->drupalGet('/admin/modules/automatic-update');
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains($failure_message);
    $assert_session->buttonNotExists('Update');
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
    $this->mockActiveCoreVersion('9.7.1');
    $this->checkForUpdates();

    $this->drupalGet($update_form_url);

    $assert_session = $this->assertSession();
    $assert_session->pageTextContains('Updates were found, but they must be performed manually. See the list of available updates for more information.');
    $this->clickLink('the list of available updates');
    $assert_session->elementExists('css', 'table.update');
    $this->assertNoUpdateButtons();
  }

  /**
   * Tests deleting an existing update.
   */
  public function testDeleteExistingUpdate(): void {
    (new StageFixtureManipulator())
      ->setCorePackageVersion('9.8.1')
      ->setReadyToCommit();
    $conflict_message = 'Cannot begin an update because another Composer operation is currently in progress.';
    $cancelled_message = 'The update was successfully cancelled.';

    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();
    $this->mockActiveCoreVersion('9.8.0');
    $this->checkForUpdates();

    $this->drupalGet('/admin/modules/update');
    $page->pressButton('Update to 9.8.1');
    $this->checkForMetaRefresh();
    $this->assertUpdateStagedTimes(1);

    // Confirm we are on the confirmation page.
    $this->assertUpdateReady('9.8.1');
    $assert_session->buttonExists('Continue');

    // If we try to return to the start page, we should be redirected back to
    // the confirmation page.
    $this->drupalGet('/admin/modules/update');
    $this->assertUpdateReady('9.8.1');

    // Delete the existing update.
    $page->pressButton('Cancel update');
    $assert_session->addressEquals('/admin/reports/updates/update');
    $assert_session->pageTextContains($cancelled_message);
    $assert_session->pageTextNotContains($conflict_message);
    // Ensure we can start another update after deleting the existing one.
    $page->pressButton('Update to 9.8.1');
    $this->checkForMetaRefresh();

    // Confirm we are on the confirmation page.
    $this->assertUpdateReady('9.8.1');
    $this->assertUpdateStagedTimes(2);
    $assert_session->buttonExists('Continue');

    // Log in as another administrative user and ensure that we cannot begin an
    // update because the previous session already started one.
    $account = $this->createUser([], NULL, TRUE);
    $this->drupalLogin($account);
    $this->drupalGet('/admin/reports/updates/update');
    $assert_session->pageTextContains($conflict_message);
    $this->assertNoUpdateButtons();
    // We should be able to delete the previous update, then start a new one.
    $page->pressButton('Delete existing update');
    $assert_session->pageTextContains('Staged update deleted');
    $assert_session->pageTextNotContains($conflict_message);
    $page->pressButton('Update to 9.8.1');
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
    $destroy_error = 'Cannot destroy the stage directory while it is being applied to the active directory.';
    $assert_session->pageTextContains($destroy_error);
    $assert_session->pageTextNotContains($cancelled_message);

    // We should get the same error if we log in as another user and try to
    // delete the staged update.
    $user = $this->createUser([
      'administer software updates',
      'access site in maintenance mode',
    ]);
    $this->drupalLogin($user);
    $this->drupalGet('/admin/reports/updates/update');
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
    $results = [$this->createValidationResult(SystemManager::REQUIREMENT_ERROR)];
    TestSubscriber1::setTestResult($results, PreApplyEvent::class);
    $page->pressButton('Update to 9.8.1');
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
  public function providerStagedDatabaseUpdates(): array {
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
   *
   * @requires PHP >= 8.0
   */
  public function testStagedDatabaseUpdates(bool $maintenance_mode_on): void {
    (new StageFixtureManipulator())
      ->setCorePackageVersion('9.8.1')
      ->setReadyToCommit();
    $this->mockActiveCoreVersion('9.8.0');
    $this->checkForUpdates();
    $this->container->get('theme_installer')->install(['automatic_updates_theme_with_updates']);
    $cached_message = $this->setAndAssertCachedMessage();

    $state = $this->container->get('state');
    $state->set('system.maintenance_mode', $maintenance_mode_on);

    // Flag a warning, which will not block the update but should be displayed
    // on the updater form.
    $expected_results = [$this->createValidationResult(SystemManager::REQUIREMENT_WARNING)];
    TestSubscriber1::setTestResult($expected_results, StatusCheckEvent::class);
    $messages = reset($expected_results)->getMessages();

    StagedDatabaseUpdateValidator::setExtensionsWithUpdates(['system', 'automatic_updates_theme_with_updates']);

    $page = $this->getSession()->getPage();
    $this->drupalGet('/admin/modules/update');
    // The warning should be visible.
    $assert_session = $this->assertSession();
    $assert_session->pageTextContains(reset($messages));
    $assert_session->pageTextNotContains($cached_message);
    $page->pressButton('Update to 9.8.1');
    $this->checkForMetaRefresh();
    $this->assertUpdateStagedTimes(1);
    $this->assertUpdateReady('9.8.1');
    // Simulate a staged database update in the automatic_updates_test module.
    // We must do this after the update has started, because the pending updates
    // validator will prevent an update from starting.
    $state->set('automatic_updates_test.new_update', TRUE);
    // The warning from the updater form should be repeated, and we should see
    // a warning about pending database updates. Once the staged changes have
    // been applied, we should be redirected to update.php, where neither
    // warning should be visible.
    $assert_session->pageTextContains(reset($messages));

    // Ensure that a list of pending database updates is visible, along with a
    // short explanation, in the warning messages.
    $possible_update_message = 'Possible database updates have been detected in the following extensions.<ul><li>System</li><li>Automatic Updates Theme With Updates</li></ul>';
    $warning_messages = $assert_session->elementExists('css', 'div[data-drupal-messages] div[aria-label="Warning message"]');
    $this->assertStringContainsString($possible_update_message, $warning_messages->getHtml());
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
    $assert_session->pageTextContainsOnce('An error has occurred.');
    $assert_session->pageTextContainsOnce('Please continue to the error page');
    $page->clickLink('the error page');
    $assert_session->pageTextContains('Some modules have database schema updates to install. You should run the database update script immediately.');
    $assert_session->linkExists('database update script');
    $assert_session->linkByHrefExists('/update.php');
    $page->clickLink('database update script');
    $assert_session->addressEquals('/update.php');
    $assert_session->pageTextNotContains($cached_message);
    $assert_session->pageTextNotContains(reset($messages));
    $assert_session->pageTextNotContains($possible_update_message);
    $this->assertTrue($state->get('system.maintenance_mode'));
    $page->clickLink('Continue');
    // @see automatic_updates_update_1191934()
    $assert_session->pageTextContains('Dynamic automatic_updates_update_1191934');
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
   *   The test cases.
   */
  public function providerSuccessfulUpdate(): array {
    return [
      'Modules page, maintenance mode on' => [
        '/admin/modules/update',
        TRUE,
      ],
      'Modules page, maintenance mode off' => [
        '/admin/modules/update',
        FALSE,
      ],
      'Reports page, maintenance mode on' => [
        '/admin/reports/updates/update',
        TRUE,
      ],
      'Reports page, maintenance mode off' => [
        '/admin/reports/updates/update',
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
    (new StageFixtureManipulator())
      ->setCorePackageVersion('9.8.1')
      ->setReadyToCommit();
    $assert_session = $this->assertSession();
    $this->mockActiveCoreVersion('9.8.0');
    $this->checkForUpdates();
    $state = $this->container->get('state');
    $state->set('system.maintenance_mode', $maintenance_mode_on);
    $page = $this->getSession()->getPage();
    $cached_message = $this->setAndAssertCachedMessage();

    $this->drupalGet($update_form_url);
    $assert_session->pageTextNotContains($cached_message);
    $page->pressButton('Update to 9.8.1');
    $this->checkForMetaRefresh();
    $this->assertUpdateStagedTimes(1);
    $this->assertUpdateReady('9.8.1');
    // Confirm that the site was put into maintenance mode if needed.
    $this->assertMaintenanceMode($maintenance_mode_on);
    $page->pressButton('Continue');
    $this->checkForMetaRefresh();
    $assert_session->addressEquals('/admin/reports/updates');
    $assert_session->pageTextNotContains($cached_message);
    // Confirm that the site was in maintenance before the update was applied.
    // @see \Drupal\package_manager_test_validation\EventSubscriber\TestSubscriber::handleEvent()
    $this->assertTrue($state->get(PreApplyEvent::class . '.system.maintenance_mode'));
    $assert_session->pageTextContainsOnce('Update complete!');
    // Confirm the site was returned to the original maintenance mode state.
    $this->assertMaintenanceMode($maintenance_mode_on);
    // Confirm that the apply and post-apply operations happened in
    // separate requests.
    // @see \Drupal\automatic_updates_test\EventSubscriber\RequestTimeRecorder
    $pre_apply_time = $state->get('Drupal\package_manager\Event\PreApplyEvent time');
    $post_apply_time = $state->get('Drupal\package_manager\Event\PostApplyEvent time');
    $this->assertNotEmpty($pre_apply_time);
    $this->assertNotEmpty($post_apply_time);
    $this->assertNotSame($pre_apply_time, $post_apply_time);
  }

  /**
   * Data provider for testStatusCheckerRunAfterUpdate().
   *
   * @return bool[][]
   *   The test cases.
   */
  public function providerStatusCheckerRunAfterUpdate(): array {
    return [
      'has database updates' => [TRUE],
      'does not have database updates' => [FALSE],
    ];
  }

  /**
   * Tests status checks are run after an update.
   *
   * @param bool $has_database_updates
   *   Whether the site has database updates or not.
   *
   * @dataProvider providerStatusCheckerRunAfterUpdate
   *
   * @requires PHP >= 8.0
   */
  public function testStatusCheckerRunAfterUpdate(bool $has_database_updates) {
    (new StageFixtureManipulator())
      ->setCorePackageVersion('9.8.1')
      ->setReadyToCommit();
    $assert_session = $this->assertSession();
    $this->mockActiveCoreVersion('9.8.0');
    $this->checkForUpdates();
    $page = $this->getSession()->getPage();
    // Navigate to the automatic updates form.
    $this->drupalGet('/admin/modules/update');
    $page->pressButton('Update to 9.8.1');
    $this->checkForMetaRefresh();
    $this->assertUpdateStagedTimes(1);
    $this->assertUpdateReady('9.8.1');
    // Set an error before completing the update. This error should be visible
    // on admin pages after completing the update without having to explicitly
    // run the status checks.
    TestSubscriber1::setTestResult([ValidationResult::createError([t('Error before continue.')])], StatusCheckEvent::class);
    if ($has_database_updates) {
      // Simulate a staged database update in the automatic_updates_test module.
      // We must do this after the update has started, because the pending
      // updates validator will prevent an update from starting.
      $this->container->get('state')->set('automatic_updates_test.new_update', TRUE);
      $page->pressButton('Continue');
      $this->checkForMetaRefresh();
      $assert_session->pageTextContainsOnce('An error has occurred.');
      $assert_session->pageTextContainsOnce('Please continue to the error page');
      $page->clickLink('the error page');
      $assert_session->pageTextContains('Some modules have database schema updates to install. You should run the database update script immediately.');
      $assert_session->linkExists('database update script');
      $assert_session->linkByHrefExists('/update.php');
      $page->clickLink('database update script');
      $this->assertSession()->addressEquals('/update.php');
      $assert_session->pageTextNotContains('Possible database updates have been detected in the following extension');
      $page->clickLink('Continue');
      // @see automatic_updates_update_1191934()
      $assert_session->pageTextContains('Dynamic automatic_updates_update_1191934');
      $page->clickLink('Apply pending updates');
      $this->checkForMetaRefresh();
      $assert_session->pageTextContains('Updates were attempted.');
      // PendingUpdatesValidator prevented the update to complete, so the status
      // checks weren't run.
      $this->drupalGet('/admin');
      $assert_session->pageTextContains('Your site has not recently run an update readiness check. Rerun readiness checks now.');
    }
    else {
      $page->pressButton('Continue');
      $this->checkForMetaRefresh();
      $assert_session->addressEquals('/admin/reports/updates');
      $assert_session->pageTextContainsOnce('Update complete!');
      // Status checks should display errors on admin page.
      $this->drupalGet('/admin');
      // Confirm that the status checks were run and the new error is displayed.
      $assert_session->statusMessageContains('Error before continue.', 'error');
      $assert_session->statusMessageContains(static::$errorsExplanation, 'error');
      $assert_session->pageTextNotContains('Your site has not recently run an update readiness check. Rerun readiness checks now.');
    }
  }

  /**
   * Data provider for testUpdateCompleteMessage().
   *
   * @return string[][]
   *   The test cases.
   */
  public function providerUpdateCompleteMessage(): array {
    return [
      'maintenance mode off' => [FALSE],
      'maintenance mode on' => [TRUE],
    ];
  }

  /**
   * Tests the update complete message is displayed when another message exist.
   *
   * @param bool $maintenance_mode_on
   *   Whether maintenance should be on at the beginning of the update.
   *
   * @dataProvider providerUpdateCompleteMessage
   */
  public function testUpdateCompleteMessage(bool $maintenance_mode_on): void {
    (new StageFixtureManipulator())
      ->setCorePackageVersion('9.8.1')
      ->setReadyToCommit();
    $assert_session = $this->assertSession();
    $this->mockActiveCoreVersion('9.8.0');
    $this->checkForUpdates();
    $state = $this->container->get('state');
    $state->set('system.maintenance_mode', $maintenance_mode_on);
    $page = $this->getSession()->getPage();

    $this->drupalGet('/admin/modules/automatic-update');
    $page->pressButton('Update to 9.8.1');
    $this->checkForMetaRefresh();
    // Confirm that the site was put into maintenance mode if needed.
    $custom_message = 'custom status message.';
    TestSubscriber1::setMessage($custom_message, PostApplyEvent::class);
    $page->pressButton('Continue');
    $this->checkForMetaRefresh();
    $assert_session->pageTextContainsOnce($custom_message);
    $assert_session->pageTextContainsOnce('Update complete!');
  }

  /**
   * Tests what happens when a staged update is deleted without being destroyed.
   */
  public function testStagedUpdateDeletedImproperly(): void {
    (new StageFixtureManipulator())
      ->setCorePackageVersion('9.8.1')
      ->setReadyToCommit();
    $this->mockActiveCoreVersion('9.8.0');
    $this->checkForUpdates();

    $page = $this->getSession()->getPage();
    $this->drupalGet('/admin/modules/update');
    $page->pressButton('Update to 9.8.1');
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
    $assert_session->addressEquals('/admin/reports/updates/update');
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
    $this->mockActiveCoreVersion('9.8.0');
    $this->checkForUpdates();

    $this->drupalGet('/admin/modules/update');
    $error = new \Exception('Some Exception');
    TestSubscriber1::setException($error, PostRequireEvent::class);
    $assert_session->pageTextNotContains(static::$errorsExplanation);
    $assert_session->pageTextNotContains(static::$warningsExplanation);
    $page->pressButton('Update to 9.8.1');
    $this->checkForMetaRefresh();
    $this->assertUpdateStagedTimes(1);
    $assert_session->pageTextContainsOnce('An error has occurred.');
    $page->clickLink('the error page');
    $assert_session->addressEquals('/admin/modules/update');
    $assert_session->pageTextNotContains('Cannot begin an update because another Composer operation is currently in progress.');
    $assert_session->buttonNotExists('Delete existing update');
    $assert_session->pageTextContains('Some Exception');
    $assert_session->buttonExists('Update');
  }

  /**
   * Tests that update cannot be completed via the UI if a status check fails.
   */
  public function testNoContinueOnError(): void {
    $session = $this->getSession();
    $assert_session = $this->assertSession();
    $page = $session->getPage();
    $this->mockActiveCoreVersion('9.8.0');
    $this->checkForUpdates();
    $this->drupalGet('/admin/modules/automatic-update');
    $page->pressButton('Update to 9.8.1');
    $this->checkForMetaRefresh();
    $this->assertUpdateStagedTimes(1);

    $error_messages = [
      t("The only thing we're allowed to do is to"),
      t("believe that we won't regret the choice"),
      t("we made."),
    ];
    $summary = t('some generic summary');
    $error = ValidationResult::createError($error_messages, $summary);
    TestSubscriber::setTestResult([$error], StatusCheckEvent::class);
    $this->getSession()->reload();
    $assert_session->pageTextContains($summary);
    foreach ($error_messages as $message) {
      $assert_session->pageTextContains($message);
    }
    $assert_session->buttonNotExists('Continue');
    $assert_session->buttonExists('Cancel update');
  }

  /**
   * Tests that update can be completed even if a status check throws a warning.
   */
  public function testContinueOnWarning(): void {
    $session = $this->getSession();

    $this->mockActiveCoreVersion('9.8.0');
    $this->checkForUpdates();
    $this->drupalGet('/admin/modules/automatic-update');
    $session->getPage()->pressButton('Update to 9.8.1');
    $this->checkForMetaRefresh();
    $this->assertUpdateStagedTimes(1);

    $messages = [
      t("The only thing we're allowed to do is to"),
      t("believe that we won't regret the choice"),
      t("we made."),
    ];
    $summary = t('some generic summary');
    $warning = ValidationResult::createWarning($messages, $summary);
    TestSubscriber::setTestResult([$warning], StatusCheckEvent::class);
    $session->reload();

    $assert_session = $this->assertSession();
    $assert_session->buttonExists('Continue');
    $assert_session->pageTextContains($summary);
    foreach ($messages as $message) {
      $assert_session->pageTextContains($message);
    }
  }

  /**
   * Sets an error message, runs status checks, and asserts it is displayed.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The cached error check message.
   */
  private function setAndAssertCachedMessage(): TranslatableMarkup {
    // Store a status error, which will be cached.
    $message = t("You've not experienced Shakespeare until you have read him in the original Klingon.");
    $result = ValidationResult::createError([$message]);
    TestSubscriber1::setTestResult([$result], StatusCheckEvent::class);
    // Run the status checks a visit an admin page the message will be
    // displayed.
    $this->drupalGet('/admin/reports/status');
    $this->clickLink('Rerun readiness checks');
    $this->drupalGet('/admin');
    $this->assertSession()->pageTextContains($message);
    // Clear the results so the only way the message could appear on the pages
    // used for the update process is if they show the cached results.
    TestSubscriber1::setTestResult(NULL, StatusCheckEvent::class);

    return $message;
  }

  /**
   * Asserts maintenance is the expected value and correct message appears.
   *
   * @param bool $expected_maintenance_mode
   *   Whether maintenance mode is expected to be on or off.
   */
  private function assertMaintenanceMode(bool $expected_maintenance_mode): void {
    $this->assertSame($this->container->get('state')
      ->get('system.maintenance_mode'), $expected_maintenance_mode);
    if ($expected_maintenance_mode) {
      $this->assertSession()
        ->pageTextContains('Operating in maintenance mode.');
    }
    else {
      $this->assertSession()
        ->pageTextNotContains('Operating in maintenance mode.');
    }
  }

  /**
   * Asserts that no update buttons exist.
   */
  private function assertNoUpdateButtons(): void {
    $this->assertSession()->elementNotExists('css', "input[value*='Update']");
  }

  /**
   * Asserts that the release notes link for a given minor version is correct.
   *
   * @param int $major
   *   Major version of next minor release.
   * @param int $minor
   *   Minor version of next minor release.
   * @param string $selector
   *   The selector.
   */
  private function assertReleaseNotesLink(int $major, int $minor, string $selector): void {
    $assert_session = $this->assertSession();
    $row = $assert_session->elementExists('css', $selector);
    $link_href = $assert_session->elementExists('named', ['link', 'Release notes'], $row)->getAttribute('href');
    $this->assertSame('http://example.com/drupal-' . $major . '-' . $minor . '-0-release', $link_href);
  }

}
