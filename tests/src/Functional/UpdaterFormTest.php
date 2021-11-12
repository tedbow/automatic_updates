<?php

namespace Drupal\Tests\automatic_updates\Functional;

use Drupal\automatic_updates\Exception\UpdateException;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\ValidationResult;
use Drupal\automatic_updates_test\ReadinessChecker\TestChecker1;
use Drupal\Tests\automatic_updates\Traits\ValidationTestTrait;

/**
 * @covers \Drupal\automatic_updates\Form\UpdaterForm
 *
 * @group automatic_updates
 */
class UpdaterFormTest extends AutomaticUpdatesFunctionalTestBase {

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
    'package_manager_bypass',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->setReleaseMetadata(__DIR__ . '/../../fixtures/release-history/drupal.9.8.1-security.xml');
    $this->drupalLogin($this->rootUser);
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
    $assert_session->statusCodeEquals(200);
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
  }

  /**
   * Tests handling of errors and warnings during the update process.
   */
  public function testUpdateErrors(): void {
    $session = $this->getSession();
    $assert_session = $this->assertSession();
    $page = $session->getPage();

    // Store a fake readiness error, which will be cached.
    $message = t("You've not experienced Shakespeare until you have read him in the original Klingon.");
    $error = ValidationResult::createError([$message]);
    TestChecker1::setTestResult([$error]);

    $this->drupalGet('/admin/reports/status');
    $page->clickLink('Run readiness checks');
    $assert_session->pageTextContainsOnce((string) $message);
    // Ensure that the fake error is cached.
    $session->reload();
    $assert_session->pageTextContainsOnce((string) $message);

    $this->setCoreVersion('9.8.0');
    $this->checkForUpdates();

    // Set up a new fake error.
    $this->createTestValidationResults();
    $expected_results = $this->testResults['checker_1']['1 error'];
    TestChecker1::setTestResult($expected_results);

    // If a validator raises an error during readiness checking, the form should
    // not have a submit button.
    $this->drupalGet('/admin/modules/automatic-update');
    $assert_session->buttonNotExists('Update');
    // Since this is an administrative page, the error message should be visible
    // thanks to automatic_updates_page_top(). The readiness checks were re-run
    // during the form build, which means the new error should be cached and
    // displayed instead of the previously cached error.
    $assert_session->pageTextContainsOnce((string) $expected_results[0]->getMessages()[0]);
    $assert_session->pageTextContainsOnce(static::$errorsExplanation);
    $assert_session->pageTextNotContains(static::$warningsExplanation);
    $assert_session->pageTextNotContains((string) $message);
    TestChecker1::setTestResult(NULL);

    // Repackage the validation error as an exception, so we can test what
    // happens if a validator throws once the update has started.
    $error = new UpdateException($expected_results, 'The update exploded.');
    TestChecker1::setTestResult($error, PreCreateEvent::class);
    $session->reload();
    $assert_session->pageTextNotContains(static::$errorsExplanation);
    $assert_session->pageTextNotContains(static::$warningsExplanation);
    $page->pressButton('Update');
    $this->checkForMetaRefresh();
    // If a validator flags an error, but doesn't throw, the update should still
    // be halted.
    $assert_session->pageTextContainsOnce('An error has occurred.');
    $page->clickLink('the error page');
    $assert_session->pageTextContainsOnce((string) $expected_results[0]->getMessages()[0]);
    // Since there's only one error message, we shouldn't see the summary...
    $assert_session->pageTextNotContains($expected_results[0]->getSummary());
    // ...but we should see the exception message.
    $assert_session->pageTextContainsOnce('The update exploded.');
    // If the error is thrown in PreCreateEvent the update stage will not have
    // been created.
    $assert_session->buttonNotExists('Delete existing update');
    TestChecker1::setTestResult($expected_results, PreCreateEvent::class);
    $page->pressButton('Update');
    $this->checkForMetaRefresh();
    $assert_session->pageTextContainsOnce('An error has occurred.');
    $page->clickLink('the error page');
    // Since there's only one message, we shouldn't see the summary.
    $assert_session->pageTextNotContains($expected_results[0]->getSummary());
    $assert_session->pageTextContainsOnce((string) $expected_results[0]->getMessages()[0]);

    // If a validator flags a warning, but doesn't throw, the update should
    // continue.
    $expected_results = $this->testResults['checker_1']['1 warning'];
    TestChecker1::setTestResult($expected_results, PreCreateEvent::class);
    $page->pressButton('Update');
    $this->checkForMetaRefresh();
    $assert_session->pageTextContains('Ready to update');
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
    $assert_session->pageTextContainsOnce('Drupal cannot be automatically updated from its current version, 9.7.1, to the recommended version, 9.8.1, because automatic updates from one minor version to another are not supported.');
    $assert_session->buttonNotExists('Update');
  }

  /**
   * Tests deleting an existing update.
   *
   * @todo Add test coverage for differences between stage owner and other users
   *   in https://www.drupal.org/i/3248928.
   */
  public function testDeleteExistingUpdate() {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();
    $this->setCoreVersion('9.8.0');
    $this->checkForUpdates();

    $this->drupalGet('/admin/modules/automatic-update');
    $page->pressButton('Update');
    $this->checkForMetaRefresh();

    // Confirm we are on the confirmation page.
    $assert_session->addressEquals('/admin/automatic-update-ready');
    $assert_session->buttonExists('Continue');

    // Return to the start page.
    $this->drupalGet('/admin/modules/automatic-update');
    $assert_session->pageTextContainsOnce('Cannot begin an update because another Composer operation is currently in progress.');
    $assert_session->buttonNotExists('Update');

    // Delete the existing update.
    $page->pressButton('Delete existing update');
    $assert_session->pageTextNotContains('Cannot begin an update because another Composer operation is currently in progress.');

    // Ensure we can start another update after deleting the existing one.
    $page->pressButton('Update');
    $this->checkForMetaRefresh();

    // Confirm we are on the confirmation page.
    $assert_session->addressEquals('/admin/automatic-update-ready');
    $assert_session->buttonExists('Continue');
  }

}
