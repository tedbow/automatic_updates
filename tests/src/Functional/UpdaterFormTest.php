<?php

namespace Drupal\Tests\automatic_updates\Functional;

use Drupal\automatic_updates\AutomaticUpdatesEvents;
use Drupal\automatic_updates\Validation\ValidationResult;
use Drupal\automatic_updates_test\ReadinessChecker\TestChecker1;
use Drupal\automatic_updates_test\TestUpdater;
use Drupal\Tests\automatic_updates\Traits\ValidationTestTrait;
use Drupal\Tests\BrowserTestBase;

/**
 * @covers \Drupal\automatic_updates\Form\UpdaterForm
 *
 * @group automatic_updates
 */
class UpdaterFormTest extends BrowserTestBase {

  use ValidationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'automatic_updates',
    'automatic_updates_test',
    'update_test',
  ];

  /**
   * Sets the running version of core, as known to the Update module.
   *
   * @param string $version
   *   The version of core to set. When checking for updates, this is what the
   *   Update module will think the running version of core is.
   */
  private function setCoreVersion(string $version): void {
    $this->config('update_test.settings')
      ->set('system_info.#all.version', $version)
      ->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->config('update_test.settings')
      ->set('xml_map', [
        'drupal' => '0.0',
      ])
      ->save();
    $this->config('update.settings')
      ->set('fetch.url', $this->baseUrl . '/automatic-update-test')
      ->save();
  }

  /**
   * Tests that the form doesn't display any buttons if Drupal is up-to-date.
   *
   * @todo Mark this test as skipped if the web server is PHP's built-in, single
   *   threaded server.
   */
  public function testFormNotDisplayedIfAlreadyCurrent(): void {
    $this->setCoreVersion('9.8.1');

    $this->drupalLogin($this->rootUser);
    $this->checkForUpdates();
    $this->drupalGet('/admin/automatic-update');

    $assert_session = $this->assertSession();
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('No update available');
    $assert_session->buttonNotExists('Download these updates');
  }

  /**
   * Tests that available updates are rendered correctly in a table.
   */
  public function testTableLooksCorrect(): void {
    $this->setCoreVersion('9.8.0');

    $this->drupalLogin($this->rootUser);
    $this->checkForUpdates();
    $this->drupalGet('/admin/automatic-update');

    $assert_session = $this->assertSession();
    $cells = $assert_session->elementExists('css', '#edit-projects .update-recommended')
      ->findAll('css', 'td');
    $this->assertCount(3, $cells);
    $assert_session->elementExists('named', ['link', 'Drupal'], $cells[0]);
    $this->assertSame('9.8.0', $cells[1]->getText());
    $this->assertSame('9.8.1 (Release notes)', $cells[2]->getText());
    $release_notes = $assert_session->elementExists('named', ['link', 'Release notes'], $cells[2]);
    $this->assertSame('Release notes for Drupal', $release_notes->getAttribute('title'));
  }

  /**
   * Tests that the form runs update validators before starting the batch job.
   */
  public function testValidation(): void {
    $this->setCoreVersion('9.8.0');

    // Ensure that one of the update validators will produce an error when we
    // try to run updates.
    $this->createTestValidationResults();
    $expected_results = $this->testResults['checker_1']['1 error'];
    TestChecker1::setTestResult($expected_results, AutomaticUpdatesEvents::PRE_START);

    $this->drupalLogin($this->rootUser);
    $this->checkForUpdates();
    $this->drupalGet('/admin/automatic-update');
    $this->getSession()->getPage()->pressButton('Download these updates');

    $assert_session = $this->assertSession();
    // We should still be on the same page, having not passed validation.
    $assert_session->addressEquals('/admin/automatic-update');
    foreach ($expected_results[0]->getMessages() as $message) {
      $assert_session->pageTextContains($message);
    }
    // Since there is only one error message, we shouldn't see the summary.
    $assert_session->pageTextNotContains($expected_results[0]->getSummary());

    // Ensure the update-ready form runs pre-commit checks immediately, even
    // before it's submitted.
    $expected_results = $this->testResults['checker_1']['1 error 1 warning'];
    TestChecker1::setTestResult($expected_results, AutomaticUpdatesEvents::PRE_COMMIT);
    $this->drupalGet('/admin/automatic-update-ready');
    $assert_session->pageTextContains($expected_results['1:error']->getMessages()[0]);
    // Only show errors, not warnings.
    $assert_session->pageTextNotContains($expected_results['1:warning']->getMessages()[0]);
    // Since there is only one error message, we shouldn't see the summary. And
    // we shouldn't see the warning's summary in any case.
    $assert_session->pageTextNotContains($expected_results['1:error']->getSummary());
    $assert_session->pageTextNotContains($expected_results['1:warning']->getSummary());
  }

  /**
   * Tests that errors during the update process are displayed as messages.
   */
  public function testBatchErrorsAreForwardedToMessenger(): void {
    $this->setCoreVersion('9.8.0');

    $error = ValidationResult::createError([
      t('💥'),
    ], t('The update exploded.'));
    TestUpdater::setBeginErrors([$error]);

    $this->drupalLogin($this->rootUser);
    $this->checkForUpdates();
    $this->drupalGet('/admin/automatic-update');
    $this->submitForm([], 'Download these updates');
    $assert_session = $this->assertSession();
    $assert_session->pageTextContains('An error has occurred.');
    $this->getSession()->getPage()->clickLink('the error page');
    $assert_session->pageTextContains('💥');
    $assert_session->pageTextContains('The update exploded.');
  }

  /**
   * Checks for available updates.
   *
   * Assumes that a user with appropriate permissions is logged in.
   */
  private function checkForUpdates(): void {
    $this->drupalGet('/admin/reports/updates');
    $this->getSession()->getPage()->clickLink('Check manually');
    $this->checkForMetaRefresh();
  }

}
