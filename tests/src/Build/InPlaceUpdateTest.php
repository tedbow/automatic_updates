<?php

namespace Drupal\Tests\automatic_updates\Build;

use Drupal\automatic_updates\Services\InPlaceUpdate;
use Drupal\Component\FileSystem\FileSystem as DrupalFilesystem;
use Drupal\Component\Utility\Html;
use Drupal\Tests\automatic_updates\Build\QuickStart\QuickStartTestBase;
use GuzzleHttp\Client;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;
use Symfony\Component\Finder\Finder;

/**
 * @coversDefaultClass \Drupal\automatic_updates\Services\InPlaceUpdate
 *
 * @group Build
 * @group Update
 *
 * @requires externalCommand composer
 * @requires externalCommand curl
 * @requires externalCommand git
 * @requires externalCommand tar
 */
class InPlaceUpdateTest extends QuickStartTestBase {

  /**
   * The files which are candidates for deletion during an upgrade.
   *
   * @var string[]
   */
  protected $deletions;

  /**
   * The directory where the deletion manifest is extracted.
   *
   * @var string
   */
  protected $deletionsDestination;

  /**
   * {@inheritdoc}
   */
  protected function tearDown() {
    parent::tearDown();
    $fs = new SymfonyFilesystem();
    $fs->remove($this->deletionsDestination);
  }

  /**
   * @covers ::update
   * @dataProvider coreVersionsProvider
   */
  public function testCoreUpdate($from_version, $to_version) {
    $this->copyCodebase();
    // We have to fetch the tags for this shallow repo. It might not be a
    // shallow clone, therefore we use executeCommand instead of assertCommand.
    $this->executeCommand('git fetch --unshallow  --tags');
    $this->executeCommand("git checkout $from_version -f");
    $this->assertCommandSuccessful();
    $fs = new SymfonyFilesystem();
    $fs->chmod($this->getWorkspaceDirectory() . '/sites/default', 0700, 0000);
    $this->executeCommand('COMPOSER_DISCARD_CHANGES=true composer install --no-dev --no-interaction');
    $this->assertErrorOutputContains('Generating autoload files');
    $this->executeCommand('COMPOSER_DISCARD_CHANGES=true composer require ocramius/package-versions:^1.4 webflo/drupal-finder:^1.1 composer/semver:^1.0 drupal/php-signify:^1.0@dev --no-interaction');
    $this->assertErrorOutputContains('Generating autoload files');
    $this->installQuickStart('minimal');

    // Currently, this test has to use extension_discovery_scan_tests so we can
    // enable test modules.
    $fs->chmod($this->getWorkspaceDirectory() . '/sites/default/settings.php', 0640, 0000);
    file_put_contents($this->getWorkspaceDirectory() . '/sites/default/settings.php', '$settings[\'extension_discovery_scan_tests\'] = TRUE;' . PHP_EOL, FILE_APPEND);

    // Log in so that we can install modules.
    $this->formLogin($this->adminUsername, $this->adminPassword);
    $this->moduleEnable('automatic_updates');
    $this->moduleEnable('test_automatic_updates');

    // Confirm we are running correct Drupal version.
    $finder = new Finder();
    $finder->files()->in($this->getWorkspaceDirectory())->path('core/lib/Drupal.php');
    $finder->contains("/const VERSION = '$from_version'/");
    $this->assertTrue($finder->hasResults());

    // Assert files slated for deletion still exist.
    foreach ($this->getDeletions('drupal', $from_version, $to_version) as $deletion) {
      $this->assertFileExists($this->getWorkspaceDirectory() . DIRECTORY_SEPARATOR . $deletion);
    }

    // Assert that the site is functional before updating.
    $this->visit();
    $this->assertDrupalVisit();

    // Update the site.
    $this->visit("/test_automatic_updates/in-place-update/drupal/core/$from_version/$to_version");
    $this->assertDrupalVisit();

    // Assert that the update worked.
    $assert = $this->getMink()->assertSession();
    $assert->pageTextContains('Update successful');
    $finder = new Finder();
    $finder->files()->in($this->getWorkspaceDirectory())->path('core/lib/Drupal.php');
    $finder->contains("/const VERSION = '$to_version'/");
    $this->assertTrue($finder->hasResults());
    $this->visit('/admin/reports/status');
    $assert->pageTextContains("Drupal Version $to_version");

    // Assert files slated for deletion are now gone.
    foreach ($this->getDeletions('drupal', $from_version, $to_version) as $deletion) {
      $this->assertFileNotExists($this->getWorkspaceDirectory() . DIRECTORY_SEPARATOR . $deletion);
    }
  }

  /**
   * @covers ::update
   * @dataProvider contribProjectsProvider
   */
  public function testContribUpdate($project, $project_type, $from_version, $to_version) {
    $this->copyCodebase();
    $fs = new SymfonyFilesystem();
    $fs->chmod($this->getWorkspaceDirectory() . '/sites/default', 0700, 0000);
    $this->executeCommand('COMPOSER_DISCARD_CHANGES=true composer install --no-dev --no-interaction');
    $this->assertErrorOutputContains('Generating autoload files');
    $this->executeCommand('COMPOSER_DISCARD_CHANGES=true composer require ocramius/package-versions:^1.4 webflo/drupal-finder:^1.1 composer/semver:^1.0 drupal/php-signify:^1.0@dev --no-interaction');
    $this->assertErrorOutputContains('Generating autoload files');
    $this->installQuickStart('standard');

    // Download the project.
    $fs->mkdir($this->getWorkspaceDirectory() . "/{$project_type}s/contrib/$project");
    $this->executeCommand("curl -fsSL https://ftp.drupal.org/files/projects/$project-$from_version.tar.gz | tar xvz -C {$project_type}s/contrib/$project --strip 1");
    $this->assertCommandSuccessful();
    $finder = new Finder();
    $finder->files()->in($this->getWorkspaceDirectory())->path("{$project_type}s/contrib/$project/$project.info.yml");
    $finder->contains("/version: '$from_version'/");
    $this->assertTrue($finder->hasResults());

    // Assert files slated for deletion still exist.
    foreach ($this->getDeletions($project, $from_version, $to_version) as $deletion) {
      $this->assertFileExists($this->getWorkspaceDirectory() . DIRECTORY_SEPARATOR . $deletion);
    }

    // Currently, this test has to use extension_discovery_scan_tests so we can
    // enable test modules.
    $fs->chmod($this->getWorkspaceDirectory() . '/sites/default/settings.php', 0640, 0000);
    file_put_contents($this->getWorkspaceDirectory() . '/sites/default/settings.php', '$settings[\'extension_discovery_scan_tests\'] = TRUE;' . PHP_EOL, FILE_APPEND);

    // Log in so that we can install projects.
    $this->formLogin($this->adminUsername, $this->adminPassword);
    $this->moduleEnable('automatic_updates');
    $this->moduleEnable('test_automatic_updates');
    if (is_callable([$this, "{$project_type}Enable"])) {
      call_user_func([$this, "{$project_type}Enable"], $project);
    }

    // Assert that the site is functional before updating.
    $this->visit();
    $this->assertDrupalVisit();

    // Update the contrib project.
    $this->visit("/automatic_updates/in-place-update/$project/$project_type/$from_version/$to_version");
    $this->assertDrupalVisit();

    // Assert that the update worked.
    $assert = $this->getMink()->assertSession();
    $assert->pageTextContains('Update successful');
    $finder = new Finder();
    $finder->files()->in($this->getWorkspaceDirectory())->path("{$project_type}s/contrib/$project/$project.info.yml");
    $finder->contains("/version: '$to_version'/");
    $this->assertTrue($finder->hasResults());
    $this->assertDrupalVisit();

    // Assert files slated for deletion are now gone.
    foreach ($this->getDeletions($project, $from_version, $to_version) as $deletion) {
      $this->assertFileNotExists($this->getWorkspaceDirectory() . DIRECTORY_SEPARATOR . $deletion);
    }
  }

  /**
   * Core versions data provider.
   */
  public function coreVersionsProvider() {
    $datum[] = [
      'from' => '8.7.0',
      'to' => '8.7.1',
    ];
    $datum[] = [
      'from' => '8.7.1',
      'to' => '8.7.2',
    ];
    $datum[] = [
      'from' => '8.7.2',
      'to' => '8.7.3',
    ];
    $datum[] = [
      'from' => '8.7.3',
      'to' => '8.7.4',
    ];
    $datum[] = [
      'from' => '8.7.4',
      'to' => '8.7.5',
    ];
    $datum[] = [
      'from' => '8.7.5',
      'to' => '8.7.6',
    ];
    $datum[] = [
      'from' => '8.7.6',
      'to' => '8.7.7',
    ];
    return $datum;
  }

  /**
   * Contrib project data provider.
   */
  public function contribProjectsProvider() {
    $datum[] = [
      'project' => 'bootstrap',
      'type' => 'theme',
      'from' => '8.x-3.19',
      'to' => '8.x-3.20',
    ];
    $datum[] = [
      'project' => 'token',
      'type' => 'module',
      'from' => '8.x-1.4',
      'to' => '8.x-1.5',
    ];
    return $datum;
  }

  /**
   * Helper method to retrieve files slated for deletion.
   */
  protected function getDeletions($project, $from_version, $to_version) {
    if (isset($this->deletions)) {
      return $this->deletions;
    }
    $this->deletions = [];
    $http_client = new Client();
    $filesystem = new SymfonyFilesystem();
    $this->deletionsDestination = DrupalFileSystem::getOsTemporaryDirectory() . DIRECTORY_SEPARATOR . "$project-" . rand(10000, 99999) . microtime(TRUE);
    $filesystem->mkdir($this->deletionsDestination);
    $file_name = "$project-$from_version-to-$to_version.zip";
    $zip_file = $this->deletionsDestination . DIRECTORY_SEPARATOR . $file_name;
    $http_client->get("https://www.drupal.org/in-place-updates/$project/$file_name", ['sink' => $zip_file]);
    $zip = new \ZipArchive();
    $zip->open($zip_file);
    $zip->extractTo($this->deletionsDestination, [InPlaceUpdate::DELETION_MANIFEST]);
    $handle = fopen($this->deletionsDestination . DIRECTORY_SEPARATOR . InPlaceUpdate::DELETION_MANIFEST, 'r');
    if ($handle) {
      while (($deletion = fgets($handle)) !== FALSE) {
        if ($result = trim($deletion)) {
          $this->deletions[] = $result;
        }
      }
      fclose($handle);
    }
    return $this->deletions;
  }

  /**
   * Helper method that uses Drupal's module page to enable a module.
   */
  protected function moduleEnable($module_name) {
    $this->visit('/admin/modules');
    $field = Html::getClass("edit-modules $module_name enable");
    // No need to enable a module if it is already enabled.
    if ($this->getMink()->getSession()->getPage()->findField($field)->isChecked()) {
      return;
    }
    $assert = $this->getMink()->assertSession();
    $assert->fieldExists($field)->check();
    $session = $this->getMink()->getSession();
    $session->getPage()->findButton('Install')->submit();
    $assert->fieldExists($field)->isChecked();
  }

  /**
   * Helper method that uses Drupal's theme page to enable a theme.
   */
  protected function themeEnable($theme_name) {
    $this->moduleEnable('test_automatic_updates');
    $this->visit("/admin/appearance/default?theme=$theme_name");
    $assert = $this->getMink()->assertSession();
    $assert->pageTextNotContains('theme was not found');
  }

}