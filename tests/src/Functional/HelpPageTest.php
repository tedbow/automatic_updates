<?php

declare(strict_types = 1);

namespace Drupal\Tests\automatic_updates\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\package_manager\Traits\AssertPreconditionsTrait;

/**
 * @group automatic_updates
 * @internal
 */
class HelpPageTest extends BrowserTestBase {

  use AssertPreconditionsTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'automatic_updates',
    'help',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests that the help page for Automatic Updates loads correctly.
   */
  public function testHelpPage(): void {
    $user = $this->createUser([
      'access administration pages',
    ]);
    $this->drupalLogin($user);
    $this->drupalGet('/admin/help/automatic_updates');

    $assert_session = $this->assertSession();
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Automatic Updates');
  }

}
