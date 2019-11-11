<?php

namespace Drupal\Tests\automatic_updates\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests access permission to log page.
 *
 * @group automatic_updates
 */
class LogPageTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'views',
    'dblog',
    'automatic_updates',
  ];

  /**
   * A user with permission to administer software updates.
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
   * Tests that the log page is displayed.
   */
  public function testLogPageExists() {
    $this->drupalGet('admin/reports/automatic_updates_log');

    $this->assertSession()->statusCodeEquals(200);
  }

}
