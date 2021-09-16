<?php

namespace Drupal\Tests\automatic_updates\Build;

/**
 * Tests an end-to-end update of Drupal core.
 *
 * @group automatic_updates
 */
class CoreUpdateTest extends UpdateTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Build the test site and alter its copy of core so that it thinks it's
    // running Drupal 9.8.0, which will never actually exist in the real world.
    // Then, prepare a secondary copy of the core code base, masquerading as
    // Drupal 9.8.1, which will be the version of core we update to. These two
    // versions are referenced in the fake release metadata in our fake release
    // metadata (see fixtures/release-history/drupal.0.0.xml).
    $this->createTestSite();
    $this->setCoreVersion($this->getWebRoot() . '/core', '9.8.0');
    $this->alterPackage($this->getWorkspaceDirectory(), $this->getConfigurationForUpdate('9.8.1'));

    // Install Drupal and ensure it's using the fake release metadata to fetch
    // information about available updates.
    $this->installQuickStart('minimal');
    $this->setReleaseMetadata(['drupal' => '0.0']);
    $this->formLogin($this->adminUsername, $this->adminPassword);
    $this->installModules([
      'automatic_updates',
      'automatic_updates_test',
      'update_test',
    ]);

    // Ensure that Drupal thinks we are running 9.8.0, then refresh information
    // about available updates.
    $this->assertCoreVersion('9.8.0');
    $this->checkForUpdates();
    // Ensure that an update to 9.8.1 is available.
    $this->visit('/admin/modules/automatic-update');
    $this->getMink()->assertSession()->pageTextContains('9.8.1');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->destroyBuild) {
      $this->deleteCopiedPackages();
    }
    parent::tearDown();
  }

  /**
   * Modifies a Drupal core code base to set its version.
   *
   * @param string $dir
   *   The directory of the Drupal core code base.
   * @param string $version
   *   The version number to set.
   */
  private function setCoreVersion(string $dir, string $version): void {
    $this->alterPackage($dir, ['version' => $version]);

    $drupal_php = "$dir/lib/Drupal.php";
    $this->assertIsWritable($drupal_php);
    $code = file_get_contents($drupal_php);
    $code = preg_replace("/const VERSION = '([0-9]+\.?){3}(-dev)?';/", "const VERSION = '$version';", $code);
    file_put_contents($drupal_php, $code);
  }

  /**
   * Returns composer.json changes that are needed to update core.
   *
   * @param string $version
   *   The version of core we will be updating to.
   *
   * @return array
   *   The changes to merge into the test site's composer.json.
   */
  protected function getConfigurationForUpdate(string $version): array {
    // Create a fake version of core with the given version number, and change
    // its README so that we can actually be certain that we update to this
    // fake version.
    $dir = $this->copyPackage($this->getWebRoot() . '/core');
    $this->setCoreVersion($dir, $version);
    file_put_contents("$dir/README.txt", "Placeholder for Drupal core $version.");

    return [
      'repositories' => [
        'drupal/core' => [
          'type' => 'path',
          'url' => $dir,
          'options' => [
            'symlink' => FALSE,
          ],
        ],
      ],
    ];
  }

  /**
   * Tests an end-to-end core update via the UI.
   */
  public function testUi(): void {
    $mink = $this->getMink();
    $page = $mink->getSession()->getPage();
    $assert_session = $mink->assertSession();

    $this->visit('/admin/modules/automatic-update');
    $page->pressButton('Download these updates');
    $this->waitForBatchJob();
    $assert_session->pageTextContains('Ready to update');
    $page->pressButton('Continue');
    $this->waitForBatchJob();
    $assert_session->pageTextContains('Update complete!');
    $this->assertUpdateSuccessful();
  }

  /**
   * Tests an end-to-end core update via cron.
   */
  public function testCron(): void {
    $this->visit('/admin/reports/status');
    $this->getMink()->getSession()->getPage()->clickLink('Run cron');
    $this->assertUpdateSuccessful();
  }

  /**
   * Asserts that Drupal core was successfully updated.
   */
  private function assertUpdateSuccessful(): void {
    // The status page should report that we're running Drupal 9.8.1.
    $this->assertCoreVersion('9.8.1');
    // The fake placeholder text from ::getConfigurationForUpdate() should be
    // present in the README.
    $placeholder = file_get_contents($this->getWebRoot() . '/core/README.txt');
    $this->assertSame('Placeholder for Drupal core 9.8.1.', $placeholder);
  }

}
