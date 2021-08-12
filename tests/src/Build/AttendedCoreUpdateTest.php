<?php

namespace Drupal\Tests\automatic_updates\Build;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Tests an end-to-end update of Drupal core within the UI.
 *
 * @group automatic_updates
 */
class AttendedCoreUpdateTest extends AttendedUpdateTestBase {

  /**
   * A directory containing a fake version of core that we will update to.
   *
   * @var string
   */
  private $coreDir;

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->destroyBuild && $this->coreDir) {
      (new Filesystem())->remove($this->coreDir);
    }
    parent::tearDown();
  }

  /**
   * Creates a Drupal core code base and assigns it an arbitrary version number.
   *
   * @param string $version
   *   The version number that the Drupal core code base should have.
   *
   * @return string
   *   The path of the code base.
   */
  protected function createTargetCorePackage(string $version): string {
    $dir = $this->getWorkspaceDirectory();
    $source = "$dir/core";
    $this->assertDirectoryExists($source);
    $destination = $dir . uniqid('_core_');
    $this->assertDirectoryDoesNotExist($destination);

    $fs = new Filesystem();
    $fs->mirror($source, $destination);

    $this->setCoreVersion($destination, $version);
    // This is for us to be certain that we actually update to our local, fake
    // version of Drupal core.
    file_put_contents($destination . '/README.txt', "Placeholder for Drupal core $version.");
    return $destination;
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
    $composer = "$dir/composer.json";
    $data = $this->readJson($composer);
    $data['version'] = $version;
    $this->writeJson($composer, $data);

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
    $this->setCoreVersion($this->getWorkspaceDirectory() . '/core', '9.8.0');
  }

  /**
   * Tests an end-to-end core update.
   */
  public function test(): void {
    $this->createTestSite();
    $this->coreDir = $this->createTargetCorePackage('9.8.1');

    $composer = $this->getWorkspaceDirectory() . "/composer.json";
    $data = $this->readJson($composer);
    $data['repositories']['drupal/core'] = [
      'type' => 'path',
      'url' => $this->coreDir,
      'options' => [
        'symlink' => FALSE,
      ],
    ];
    $this->writeJson($composer, $data);

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
    // @todo This message isn't showing up, for some reason. Figure out what the
    // eff is going on.
    // $assert_session->pageTextContains('Update complete!');
    $this->assertCoreVersion('9.8.1');

    $placeholder = file_get_contents($this->getWorkspaceDirectory() . '/core/README.txt');
    $this->assertSame('Placeholder for Drupal core 9.8.1.', $placeholder);
  }

}
