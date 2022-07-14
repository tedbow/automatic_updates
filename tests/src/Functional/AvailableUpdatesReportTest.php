<?php

namespace Drupal\Tests\automatic_updates\Functional;

use Drupal\Core\Url;

/**
 * Tests changes to the Available Updates report provided by the Update module.
 *
 * @group automatic_updates
 */
class AvailableUpdatesReportTest extends AutomaticUpdatesFunctionalTestBase {

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
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $user = $this->createUser([
      'administer site configuration',
      'administer software updates',
      'access administration pages',
      'access site reports',
    ]);
    $this->drupalLogin($user);
  }

  /**
   * Tests the Available Updates report links are correct.
   */
  public function testReportLinks(): void {
    $assert = $this->assertSession();
    $form_url = Url::fromRoute('automatic_updates.report_update')->toString();

    $this->config('automatic_updates.settings')->set('allow_core_minor_updates', TRUE)->save();
    $fixture_directory = __DIR__ . '/../../fixtures/release-history/';
    $this->setReleaseMetadata("$fixture_directory/drupal.9.8.1-security.xml");
    $this->setCoreVersion('9.8.0');
    $this->checkForUpdates();
    $assert->pageTextContains('Security update required! Update now');
    $assert->elementAttributeContains('named', ['link', 'Update now'], 'href', $form_url);
    $this->assertVersionLink('9.8.1', $form_url);

    $this->setReleaseMetadata("$fixture_directory/drupal.9.8.2-older-sec-release.xml");
    $this->setCoreVersion('9.7.0');
    $this->checkForUpdates();
    $assert->pageTextContains('Security update required! Update now');

    $assert->elementAttributeContains('named', ['link', 'Update now'], 'href', $form_url);
    // Releases that will available on the form should link to the form.
    $this->assertVersionLink('9.8.2', $form_url);
    $this->assertVersionLink('9.7.1', $form_url);
    // Releases that will not be available in the form should link to the
    // project release page.
    $this->assertVersionLink('9.8.1', 'http://example.com/drupal-9-8-1-release');

    $this->setReleaseMetadata("$fixture_directory/drupal.9.8.2.xml");
    $this->checkForUpdates();
    $assert->pageTextContains('Update available Update now');
    $assert->elementAttributeContains('named', ['link', 'Update now'], 'href', $form_url);
    $this->assertVersionLink('9.8.2', $form_url);
  }

  /**
   * Asserts the version download link is correct.
   *
   * @param string $version
   *   The version.
   * @param string $url
   *   The expected URL.
   */
  private function assertVersionLink(string $version, string $url): void {
    $assert = $this->assertSession();
    $row = $assert->elementExists('css', "table.update .project-update__version:contains(\"$version\")");
    $link = $assert->elementExists('named', ['link', 'Download'], $row);
    $this->assertStringEndsWith($url, $link->getAttribute('href'));
  }

}
