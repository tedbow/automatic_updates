<?php

namespace Drupal\Tests\automatic_updates\Functional;

use Drupal\automatic_updates\AutomaticUpdatesEvents;
use Drupal\automatic_updates\Exception\UpdateException;
use Drupal\automatic_updates_test\ReadinessChecker\TestChecker1;
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
    'composer_stager_bypass',
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
  protected function setUp(): void {
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
   * Tests handling of errors and warnings during the update process.
   */
  public function testUpdateErrors(): void {
    $session = $this->getSession();
    $assert_session = $this->assertSession();
    $page = $session->getPage();

    $this->setCoreVersion('9.8.0');
    $this->createTestValidationResults();

    $expected_results = $this->testResults['checker_1']['1 error'];
    // Repackage the validation error as an exception, so we can test what
    // happens if a validator throws.
    $error = new UpdateException($expected_results, 'The update exploded.');
    TestChecker1::setTestResult($error, AutomaticUpdatesEvents::PRE_START);

    $this->drupalLogin($this->rootUser);
    $this->checkForUpdates();
    $this->drupalGet('/admin/automatic-update');
    $page->pressButton('Download these updates');
    $this->checkForMetaRefresh();
    $assert_session->pageTextContains('An error has occurred.');
    $page->clickLink('the error page');
    $assert_session->pageTextContains((string) $expected_results[0]->getMessages()[0]);
    // Since there's only one error message, we shouldn't see the summary...
    $assert_session->pageTextNotContains($expected_results[0]->getSummary());
    // ...but we should see the exception message.
    $assert_session->pageTextContains('The update exploded.');

    // If a validator flags an error, but doesn't throw, the update should still
    // be halted.
    TestChecker1::setTestResult($expected_results, AutomaticUpdatesEvents::PRE_START);
    $this->deleteStagedUpdate();
    $page->pressButton('Download these updates');
    $this->checkForMetaRefresh();
    $assert_session->pageTextContains('An error has occurred.');
    $page->clickLink('the error page');
    // Since there's only one message, we shouldn't see the summary.
    $assert_session->pageTextNotContains($expected_results[0]->getSummary());
    $assert_session->pageTextContains((string) $expected_results[0]->getMessages()[0]);

    // If a validator flags a warning, but doesn't throw, the update should
    // continue.
    $expected_results = $this->testResults['checker_1']['1 warning'];
    TestChecker1::setTestResult($expected_results, AutomaticUpdatesEvents::PRE_START);
    $session->reload();
    $this->deleteStagedUpdate();
    $page->pressButton('Download these updates');
    $this->checkForMetaRefresh();
    $assert_session->pageTextContains('Ready to update');
  }

  /**
   * Deletes a staged, failed update.
   */
  private function deleteStagedUpdate(): void {
    $session = $this->getSession();
    $session->getPage()->pressButton('Delete existing update');
    $this->assertSession()->pageTextContains('Staged update deleted');
    $session->reload();
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
