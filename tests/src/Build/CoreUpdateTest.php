<?php

namespace Drupal\Tests\automatic_updates\Build;

use Drupal\Composer\Composer;

/**
 * Tests an end-to-end update of Drupal core.
 *
 * @group automatic_updates
 */
class CoreUpdateTest extends UpdateTestBase {

  /**
   * {@inheritdoc}
   */
  public function copyCodebase(\Iterator $iterator = NULL, $working_dir = NULL) {
    parent::copyCodebase($iterator, $working_dir);

    // Ensure that we will install Drupal 9.8.0 (a fake version that should
    // never exist in real life) initially.
    $this->setUpstreamCoreVersion('9.8.0');
  }

  /**
   * {@inheritdoc}
   */
  public function getCodebaseFinder() {
    // Don't copy .git directories and such, since that just slows things down.
    // We can use ::setUpstreamCoreVersion() to explicitly set the versions of
    // core packages required by the test site.
    return parent::getCodebaseFinder()->ignoreVCS(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function createTestProject(string $template): void {
    parent::createTestProject($template);

    // Prepare an "upstream" version of core, 9.8.1, to which we will update.
    // This version, along with 9.8.0 (which was installed initially), is
    // referenced in our fake release metadata (see
    // fixtures/release-history/drupal.0.0.xml).
    $this->setUpstreamCoreVersion('9.8.1');
    $this->setReleaseMetadata(['drupal' => '9.8.1-security']);

    // Ensure that Drupal thinks we are running 9.8.0, then refresh information
    // about available updates and ensure that an update to 9.8.1 is available.
    $this->assertCoreVersion('9.8.0');
    $this->checkForUpdates();
    $this->visit('/admin/modules/automatic-update');
    $this->getMink()->assertSession()->pageTextContains('9.8.1');
  }

  /**
   * Tests an end-to-end core update via the API.
   *
   * @param string $template
   *   The template project from which to build the test site.
   *
   * @dataProvider providerTemplate
   */
  public function testApi(string $template): void {
    $this->createTestProject($template);

    $mink = $this->getMink();
    $assert_session = $mink->assertSession();

    // Ensure that the update is prevented if the web root and/or vendor
    // directories are not writable.
    $this->assertReadOnlyFileSystemError('/automatic-update-test/update/9.8.1');

    $mink->getSession()->reload();
    $assert_session->pageTextContains('9.8.1');
    $this->assertUpdateSuccessful('9.8.1');
  }

  /**
   * Tests an end-to-end core update via the UI.
   *
   * @param string $template
   *   The template project from which to build the test site.
   *
   * @dataProvider providerTemplate
   */
  public function testUi(string $template): void {
    $this->createTestProject($template);

    $mink = $this->getMink();
    $session = $mink->getSession();
    $page = $session->getPage();
    $assert_session = $mink->assertSession();

    $this->visit('/admin/modules');
    $assert_session->pageTextContains('There is a security update available for your version of Drupal.');
    $page->clickLink('Update');

    // Ensure that the update is prevented if the web root and/or vendor
    // directories are not writable.
    $this->assertReadOnlyFileSystemError(parse_url($session->getCurrentUrl(), PHP_URL_PATH));
    $session->reload();

    $assert_session->pageTextNotContains('There is a security update available for your version of Drupal.');
    $page->pressButton('Update');
    $this->waitForBatchJob();
    $assert_session->pageTextContains('Ready to update');
    $page->pressButton('Continue');
    $this->waitForBatchJob();
    $assert_session->pageTextContains('Update complete!');
    $assert_session->pageTextNotContains('There is a security update available for your version of Drupal.');
    $this->assertUpdateSuccessful('9.8.1');
  }

  /**
   * Tests an end-to-end core update via cron.
   *
   * @param string $template
   *   The template project from which to build the test site.
   *
   * @dataProvider providerTemplate
   */
  public function testCron(string $template): void {
    $this->createTestProject($template);

    $this->visit('/admin/reports/status');
    $this->getMink()->getSession()->getPage()->clickLink('Run cron');
    $this->assertUpdateSuccessful('9.8.1');
  }

  /**
   * Asserts that the update is prevented if the filesystem isn't writable.
   *
   * @param string $error_url
   *   A URL where we can see the error message which is raised when parts of
   *   the file system are not writable. This URL will be visited twice: once
   *   for the web root, and once for the vendor directory.
   */
  private function assertReadOnlyFileSystemError(string $error_url): void {
    $directories = [
      'Drupal' => rtrim($this->getWebRoot(), './'),
    ];

    // The location of the vendor directory depends on which project template
    // was used to build the test site, so just ask Composer where it is.
    $directories['vendor'] = $this->runComposer('composer config --absolute vendor-dir', 'project');

    $assert_session = $this->getMink()->assertSession();
    foreach ($directories as $type => $path) {
      chmod($path, 0555);
      $this->assertDirectoryIsNotWritable($path);
      $this->visit($error_url);
      $assert_session->pageTextContains("The $type directory \"$path\" is not writable.");
      chmod($path, 0755);
      $this->assertDirectoryIsWritable($path);
    }
  }

  /**
   * Sets the version of Drupal core to which the test site will be updated.
   *
   * @param string $version
   *   The Drupal core version to set.
   */
  private function setUpstreamCoreVersion(string $version): void {
    $workspace_dir = $this->getWorkspaceDirectory();

    // Loop through core's metapackages and plugins, and alter them as needed.
    $packages = str_replace("$workspace_dir/", NULL, $this->getCorePackages());
    foreach ($packages as $path) {
      // Assign the new upstream version.
      $this->runComposer("composer config version $version", $path);

      // If this package requires Drupal core (e.g., drupal/core-recommended),
      // make it require the new upstream version.
      $info = $this->runComposer('composer info --self --format json', $path, TRUE);
      if (isset($info['requires']['drupal/core'])) {
        $this->runComposer("composer require --no-update drupal/core:$version", $path);
      }
    }

    // Change the \Drupal::VERSION constant and put placeholder text in the
    // README so we can ensure that we really updated to the correct version.
    // @see ::assertUpdateSuccessful()
    Composer::setDrupalVersion($workspace_dir, $version);
    file_put_contents("$workspace_dir/core/README.txt", "Placeholder for Drupal core $version.");
  }

  /**
   * Asserts that a specific version of Drupal core is running.
   *
   * Assumes that a user with permission to view the status report is logged in.
   *
   * @param string $expected_version
   *   The version of core that should be running.
   */
  protected function assertCoreVersion(string $expected_version): void {
    $this->visit('/admin/reports/status');
    $item = $this->getMink()
      ->assertSession()
      ->elementExists('css', 'h3:contains("Drupal Version")')
      ->getParent()
      ->getText();
    $this->assertStringContainsString($expected_version, $item);
  }

  /**
   * Asserts that Drupal core was updated successfully.
   *
   * Assumes that a user with appropriate permissions is logged in.
   *
   * @param string $expected_version
   *   The expected active version of Drupal core.
   */
  private function assertUpdateSuccessful(string $expected_version): void {
    // The update form should not have any available updates.
    // @todo Figure out why this assertion fails when the batch processor
    //   redirects directly to the update form, instead of update.status, when
    //   updating via the UI.
    $this->visit('/admin/modules/automatic-update');
    $this->getMink()->assertSession()->pageTextContains('No update available');

    // The status page should report that we're running the expected version and
    // the README should contain the placeholder text written by
    // ::setUpstreamCoreVersion().
    $this->assertCoreVersion($expected_version);
    $placeholder = file_get_contents($this->getWebRoot() . '/core/README.txt');
    $this->assertSame("Placeholder for Drupal core $expected_version.", $placeholder);

    $info = $this->runComposer('composer info --self --format json', 'project', TRUE);

    // The production dependencies should have been updated.
    $this->assertSame($expected_version, $info['requires']['drupal/core-recommended']);
    $this->assertSame($expected_version, $info['requires']['drupal/core-composer-scaffold']);
    $this->assertSame($expected_version, $info['requires']['drupal/core-project-message']);
    // The core-vendor-hardening plugin is only used by the legacy project
    // template.
    if ($info['name'] === 'drupal/legacy-project') {
      $this->assertSame($expected_version, $info['requires']['drupal/core-vendor-hardening']);
    }
    // The production dependencies should not be listed as dev dependencies.
    $this->assertArrayNotHasKey('drupal/core-recommended', $info['devRequires']);
    $this->assertArrayNotHasKey('drupal/core-composer-scaffold', $info['devRequires']);
    $this->assertArrayNotHasKey('drupal/core-project-message', $info['devRequires']);
    $this->assertArrayNotHasKey('drupal/core-vendor-hardening', $info['devRequires']);

    // The drupal/core-dev metapackage should not be a production dependency...
    $this->assertArrayNotHasKey('drupal/core-dev', $info['requires']);
    // ...but it should have been updated in the dev dependencies.
    $this->assertSame($expected_version, $info['devRequires']['drupal/core-dev']);
  }

}
