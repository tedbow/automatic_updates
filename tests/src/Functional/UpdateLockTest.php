<?php

namespace Drupal\Tests\automatic_updates\Functional;

/**
 * Tests that only one Automatic Update operation can be performed at a time.
 *
 * @group automatic_updates
 */
class UpdateLockTest extends AutomaticUpdatesFunctionalTestBase {

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
    'package_manager_bypass',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->setReleaseMetadata(__DIR__ . '/../../fixtures/release-history/drupal.9.8.1.xml');
    $this->drupalLogin($this->rootUser);
    $this->checkForUpdates();
  }

  /**
   * Tests that only user who started an update can continue through it.
   */
  public function testLock() {
    $page = $this->getSession()->getPage();
    $assert_session = $this->assertSession();
    $this->setCoreVersion('9.8.0');
    $this->checkForUpdates();
    $permissions = ['administer software updates'];
    $user_1 = $this->createUser($permissions);
    $user_2 = $this->createUser($permissions);

    // We should be able to get partway through an update without issue.
    $this->drupalLogin($user_1);
    $this->drupalGet('/admin/modules/automatic-update');
    $page->pressButton('Update');
    $this->checkForMetaRefresh();
    $assert_session->buttonExists('Continue');
    $assert_session->addressEquals('/admin/automatic-update-ready');

    // Another user cannot show up and try to start an update, since the other
    // user already started one.
    $this->drupalLogin($user_2);
    $this->drupalGet('/admin/modules/automatic-update');
    $assert_session->buttonNotExists('Update');
    $assert_session->pageTextContains('Cannot begin an update because another Composer operation is currently in progress.');

    // If the current user did not start the update, they should not be able to
    // continue it, either.
    $this->drupalGet('/admin/automatic-update-ready');
    $assert_session->pageTextContains('Cannot continue the update because another Composer operation is currently in progress.');
    $assert_session->buttonNotExists('Continue');

    // The user who started the update should be able to continue it.
    $this->drupalLogin($user_1);
    $this->drupalGet('/admin/automatic-update-ready');
    $assert_session->pageTextNotContains('Cannot continue the update because another Composer operation is currently in progress.');
    $assert_session->buttonExists('Continue');
  }

}