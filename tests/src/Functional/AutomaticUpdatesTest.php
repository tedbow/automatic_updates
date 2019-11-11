<?php

namespace Drupal\Tests\automatic_updates\Functional;

use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests of automatic updates.
 *
 * @group automatic_updates
 */
class AutomaticUpdatesTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'automatic_updates',
    'test_automatic_updates',
    'update',
  ];

  /**
   * A user with permission to administer site configuration.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->user = $this->drupalCreateUser([
      'access administration pages',
      'administer site configuration',
      'administer software updates',
    ]);
    $this->drupalLogin($this->user);
  }

  /**
   * Tests that a PSA is displayed.
   */
  public function testPsa() {
    // Setup test PSA endpoint.
    $end_point = $this->buildUrl(Url::fromRoute('test_automatic_updates.json_test_controller'));
    $this->config('automatic_updates.settings')
      ->set('psa_endpoint', $end_point)
      ->save();
    $this->drupalGet(Url::fromRoute('system.admin'));
    $this->assertSession()->pageTextContains('Critical Release - SA-2019-02-19');
    $this->assertSession()->pageTextContains('Critical Release - PSA-Really Old');
    $this->assertSession()->pageTextContains('Seven - Moderately critical - Access bypass - SA-CONTRIB-2019');
    $this->assertSession()->pageTextContains('Standard - Moderately critical - Access bypass - SA-CONTRIB-2019');
    $this->assertSession()->pageTextNotContains('Node - Moderately critical - Access bypass - SA-CONTRIB-2019');
    $this->assertSession()->pageTextNotContains('Views - Moderately critical - Access bypass - SA-CONTRIB-2019');

    // Test site status report.
    $this->drupalGet(Url::fromRoute('system.status'));
    $this->assertSession()->pageTextContains('4 urgent announcements require your attention:');

    // Test cache.
    $end_point = $this->buildUrl(Url::fromRoute('test_automatic_updates.json_test_denied_controller'));
    $this->config('automatic_updates.settings')
      ->set('psa_endpoint', $end_point)
      ->save();
    $this->drupalGet(Url::fromRoute('system.admin'));
    $this->assertSession()->pageTextContains('Critical Release - SA-2019-02-19');

    // Test transmit errors with JSON endpoint.
    drupal_flush_all_caches();
    $this->drupalGet(Url::fromRoute('system.admin'));
    $this->assertSession()->pageTextContains("Drupal PSA endpoint $end_point is unreachable.");

    // Test disabling PSAs.
    $end_point = $this->buildUrl(Url::fromRoute('test_automatic_updates.json_test_controller'));
    $this->config('automatic_updates.settings')
      ->set('psa_endpoint', $end_point)
      ->set('enable_psa', FALSE)
      ->save();
    drupal_flush_all_caches();
    $this->drupalGet(Url::fromRoute('system.admin'));
    $this->assertSession()->pageTextNotContains('Critical Release - PSA-2019-02-19');
    $this->drupalGet(Url::fromRoute('system.status'));
    $this->assertSession()->pageTextNotContains('urgent announcements require your attention');
  }

  /**
   * Tests manually running readiness checks.
   */
  public function testReadinessChecks() {
    $ignore_paths = "modules/custom/*\nthemes/custom/*\nprofiles/custom/*";
    $this->config('automatic_updates.settings')->set('ignored_paths', $ignore_paths)
      ->save();
    // Test manually running readiness checks. A few warnings will occur.
    $this->drupalGet(Url::fromRoute('automatic_updates.settings'));
    $this->clickLink('run the readiness checks');
    $this->assertSession()->pageTextContains('Your site does not pass some readiness checks for automatic updates. Depending on the nature of the failures, it might effect the eligibility for automatic updates.');

    // Ignore specific file paths to see no readiness issues.
    $ignore_paths = "core/*\nmodules/*\nthemes/*\nprofiles/*";
    $this->config('automatic_updates.settings')->set('ignored_paths', $ignore_paths)
      ->save();
    $this->drupalGet(Url::fromRoute('automatic_updates.settings'));
    $this->clickLink('run the readiness checks');
    $this->assertSession()->pageTextContains('No issues found. Your site is completely ready for automatic updates.');
  }

}
