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
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = [
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
      'administer site configuration',
      'access administration pages',
    ]);
    $this->drupalLogin($this->user);
  }

  /**
   * Tests that a PSA is displayed.
   */
  public function testPsa() {
    $end_point = $this->buildUrl(Url::fromRoute('test_automatic_updates.json_test_controller'));
    $this->config('automatic_updates.settings')
      ->set('psa_endpoint', $end_point)
      ->save();
    $this->drupalGet(Url::fromRoute('system.admin'));
    $this->assertSession()->pageTextContains('Drupal Core PSA: Critical Release - PSA-2019-02-19');
    $this->assertSession()->pageTextNotContains('Drupal Core PSA: Critical Release - PSA-Really Old');
    $this->assertSession()->pageTextNotContains('Drupal Contrib Project PSA: Node - Moderately critical - Access bypass - SA-CONTRIB-2019');
    $this->assertSession()->pageTextContains('Drupal Contrib Project PSA: Seven - Moderately critical - Access bypass - SA-CONTRIB-2019');
    $this->assertSession()->pageTextContains('Drupal Contrib Project PSA: Standard - Moderately critical - Access bypass - SA-CONTRIB-2019');

    // Test site status report.
    $this->drupalGet(Url::fromRoute('system.status'));
    $this->assertSession()->pageTextContains('4 urgent announcements requiring your attention:');

    // Test cache.
    $end_point = 'http://localhost/automatic_updates/test-json-denied';
    $this->config('automatic_updates.settings')
      ->set('psa_endpoint', $end_point)
      ->save();
    $this->drupalGet(Url::fromRoute('system.admin'));
    $this->assertSession()->pageTextContains('Drupal Core PSA: Critical Release - PSA-2019-02-19');

    // Test transmit errors with JSON endpoint.
    drupal_flush_all_caches();
    $this->drupalGet(Url::fromRoute('system.admin'));
    $this->assertSession()->pageTextContains('Drupal PSA endpoint http://localhost/automatic_updates/test-json-denied is unreachable.');

    // Test disabling PSAs.
    $end_point = $this->buildUrl(Url::fromRoute('test_automatic_updates.json_test_controller'));
    $this->config('automatic_updates.settings')
      ->set('psa_endpoint', $end_point)
      ->set('enable_psa', FALSE)
      ->save();
    drupal_flush_all_caches();
    $this->drupalGet(Url::fromRoute('system.admin'));
    $this->assertSession()->pageTextNotContains('Drupal Core PSA: Critical Release - PSA-2019-02-19');
    $this->drupalGet(Url::fromRoute('system.status'));
    $this->assertSession()->pageTextNotContains('4 announcements requiring your attention:');
  }

}
