<?php

namespace Drupal\Tests\automatic_updates\Build;

/**
 * Tests an end-to-end update of Drupal core within the UI.
 *
 * @group automatic_updates
 */
class AttendedCoreUpdateTest extends AttendedUpdateTestBase {

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
   * {@inheritdoc}
   */
  protected function createTestSite(): void {
    parent::createTestSite();
    $this->setCoreVersion($this->getWebRoot() . '/core', '9.8.0');
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
   * Tests an end-to-end core update.
   */
  public function test(): void {
    $this->createTestSite();
    $this->alterPackage($this->getWorkspaceDirectory(), $this->getConfigurationForUpdate('9.8.1'));

    $this->installQuickStart('minimal');
    $this->setReleaseMetadata(['drupal' => '0.0']);
    $this->formLogin($this->adminUsername, $this->adminPassword);
    $this->installModules([
      'automatic_updates',
      'automatic_updates_test',
      'update_test',
    ]);

    $mink = $this->getMink();
    $page = $mink->getSession()->getPage();
    $assert_session = $mink->assertSession();

    $this->assertCoreVersion('9.8.0');
    $this->checkForUpdates();
    $this->visit('/admin/automatic-update');
    $assert_session->pageTextContains('9.8.1');
    $page->pressButton('Download these updates');
    $this->waitForBatchJob();
    $assert_session->pageTextContains('Ready to update');
    $page->pressButton('Continue');
    $this->waitForBatchJob();
    $assert_session->pageTextContains('Update complete!');
    $this->assertCoreVersion('9.8.1');

    $placeholder = file_get_contents($this->getWebRoot() . '/core/README.txt');
    $this->assertSame('Placeholder for Drupal core 9.8.1.', $placeholder);
  }

}
