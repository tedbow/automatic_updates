<?php

declare(strict_types = 1);

namespace Drupal\Tests\automatic_updates\Functional;

/**
 * Tests package manager help link is clickable.
 *
 * @group automatic_updates
 * @internal
 */
class ClickableHelpTest extends AutomaticUpdatesFunctionalTestBase {

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
  protected $defaultTheme = 'starterkit_theme';

  /**
   * Tests if composer executable is not present then the help link clickable.
   */
  public function testHelpLinkClickable(): void {
    $this->drupalLogin($this->createUser([
      'administer site configuration',
    ]));
    $this->config('package_manager.settings')
      ->set('executables.composer', '/not/matching/path/to/composer')
      ->save();
    $this->drupalGet('admin/reports/status');
    $this->assertSession()->linkByHrefExists('/admin/help/package_manager#package-manager-faq-composer-not-found');
  }

}
