<?php

namespace Drupal\Tests\automatic_updates\Build;

use Drupal\automatic_updates\Services\InPlaceUpdate;
use Drupal\Component\FileSystem\FileSystem as DrupalFilesystem;
use Drupal\Tests\automatic_updates\Build\QuickStart\QuickStartTestBase;
use Drupal\Tests\automatic_updates\Traits\InstallTestTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
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
  use InstallTestTrait;

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
   * @dataProvider coreVersionsSuccessProvider
   */
  public function testCoreUpdate($from_version, $to_version) {
    $this->installCore($from_version);
    $this->assertCoreUpgradeSuccess($from_version, $to_version);
  }

  /**
   * @covers ::update
   */
  public function testCoreRollbackUpdate() {
    $from_version = '8.7.0';
    $to_version = '8.8.5';
    $this->installCore($from_version);

    // Configure module to have db updates cause a rollback.
    $settings_php = $this->getWorkspaceDirectory() . '/sites/default/settings.php';
    $fs = new SymfonyFilesystem();
    $fs->chmod($this->getWorkspaceDirectory() . '/sites/default', 0755);
    $fs->chmod($settings_php, 0640);
    $fs->appendToFile($settings_php, PHP_EOL . '$config[\'automatic_updates.settings\'][\'database_update_handling\'] = [\'rollback\'];' . PHP_EOL);

    $this->assertCoreUpgradeFailed($from_version, $to_version);
  }

  /**
   * @covers ::update
   * @dataProvider contribProjectsProvider
   */
  public function testContribUpdate($project, $project_type, $from_version, $to_version) {
    $this->markTestSkipped('Contrib updates are not currently supported');
    $this->copyCodebase();
    $fs = new SymfonyFilesystem();
    $fs->chmod($this->getWorkspaceDirectory() . '/sites/default', 0700);
    $this->executeCommand('COMPOSER_DISCARD_CHANGES=true composer install --no-dev --no-interaction');
    $this->assertErrorOutputContains('Generating autoload files');
    $this->installQuickStart('standard');

    // Download the project.
    $fs->mkdir($this->getWorkspaceDirectory() . "/{$project_type}s/contrib/$project");
    $this->executeCommand("curl -fsSL https://ftp.drupal.org/files/projects/$project-$from_version.tar.gz | tar xvz -C {$project_type}s/contrib/$project --strip 1");
    $this->assertCommandSuccessful();
    $finder = new Finder();
    $finder->files()->in($this->getWorkspaceDirectory())->path("{$project_type}s/contrib/$project/$project.info.yml");
    $finder->contains("/version: '$from_version'/");
    $this->assertTrue($finder->hasResults(), "Expected version $from_version does not exist in {$this->getWorkspaceDirectory()}/core/lib/Drupal.php");

    // Assert files slated for deletion still exist.
    foreach ($this->getDeletions($project, $from_version, $to_version) as $deletion) {
      $this->assertFileExists($this->getWorkspaceDirectory() . DIRECTORY_SEPARATOR . $deletion);
    }

    // Currently, this test has to use extension_discovery_scan_tests so we can
    // install test modules.
    $fs = new SymfonyFilesystem();
    $settings_php = $this->getWorkspaceDirectory() . '/sites/default/settings.php';
    $fs->chmod($settings_php, 0640);
    $fs->appendToFile($settings_php, '$settings[\'extension_discovery_scan_tests\'] = TRUE;' . PHP_EOL);

    // Log in so that we can install projects.
    $this->formLogin($this->adminUsername, $this->adminPassword);
    $this->moduleInstall('update');
    $this->moduleInstall('automatic_updates');
    $this->moduleInstall('test_automatic_updates');
    $this->{"{$project_type}Install"}($project);

    // Assert that the site is functional before updating.
    $this->visit();
    $this->assertDrupalVisit();

    // Update the contrib project.
    $assert = $this->visit("/test_automatic_updates/in-place-update/$project/$project_type/$from_version/$to_version")
      ->assertSession();
    $assert->statusCodeEquals(200);
    $this->assertDrupalVisit();

    // Assert that the update worked.
    $assert->pageTextContains('Update successful');
    $finder = new Finder();
    $finder->files()->in($this->getWorkspaceDirectory())->path("{$project_type}s/contrib/$project/$project.info.yml");
    $finder->contains("/version: '$to_version'/");
    $this->assertTrue($finder->hasResults(), "Expected version $to_version does not exist in {$this->getWorkspaceDirectory()}/core/lib/Drupal.php");
    $this->assertDrupalVisit();

    // Assert files slated for deletion are now gone.
    foreach ($this->getDeletions($project, $from_version, $to_version) as $deletion) {
      $this->assertFileNotExists($this->getWorkspaceDirectory() . DIRECTORY_SEPARATOR . $deletion);
    }
  }

  /**
   * Test in-place update via cron run.
   *
   * @covers ::update
   * @see automatic_updates_cron()
   */
  public function testCronCoreUpdate() {
    $this->installCore('8.8.0');
    $filesystem = new SymfonyFilesystem();
    $filesystem->chmod($this->getWorkspaceDirectory() . '/sites/default', 0750);
    $settings_php = $this->getWorkspaceDirectory() . '/sites/default/settings.php';
    $filesystem->chmod($settings_php, 0640);
    $filesystem->appendToFile($settings_php, PHP_EOL . '$config[\'automatic_updates.settings\'][\'enable_cron_updates\'] = TRUE;' . PHP_EOL);
    $mink = $this->visit('/admin/config/system/cron');
    $mink->getSession()->getPage()->findButton('Run cron')->submit();
    $mink->assertSession()->pageTextContains('Cron ran successfully.');

    // Assert that the update worked.
    $this->assertDrupalVisit();
    $finder = new Finder();
    $finder->files()->in($this->getWorkspaceDirectory())->path('core/lib/Drupal.php');
    $finder->notContains("/const VERSION = '8.8.0'/");
    $finder->contains("/const VERSION = '8.8./");
    $this->assertTrue($finder->hasResults(), "Expected version 8.8.{x} does not exist in {$this->getWorkspaceDirectory()}/core/lib/Drupal.php");
  }

  /**
   * Core versions data provider resulting in a successful upgrade.
   */
  public function coreVersionsSuccessProvider() {
    $datum[] = [
      'from' => '8.7.2',
      'to' => '8.7.4',
    ];
    $datum[] = [
      'from' => '8.7.0',
      'to' => '8.7.1',
    ];
    $datum[] = [
      'from' => '8.7.2',
      'to' => '8.7.10',
    ];
    $datum[] = [
      'from' => '8.7.6',
      'to' => '8.7.7',
    ];
    $datum[] = [
      'from' => '8.9.0-beta1',
      'to' => '8.9.0-beta2',
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
    $filesystem = new SymfonyFilesystem();
    $this->deletionsDestination = DrupalFileSystem::getOsTemporaryDirectory() . DIRECTORY_SEPARATOR . "$project-" . mt_rand(10000, 99999) . microtime(TRUE);
    $filesystem->mkdir($this->deletionsDestination);
    $file_name = "$project-$from_version-to-$to_version.zip";
    $zip_file = $this->deletionsDestination . DIRECTORY_SEPARATOR . $file_name;
    $this->doGetArchive($project, $file_name, $zip_file);
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
   * Get the archive with protection against 429s.
   *
   * @param string $project
   *   The project.
   * @param string $file_name
   *   The filename.
   * @param string $zip_file
   *   The zip file path.
   * @param int $delay
   *   (optional) The delay.
   */
  protected function doGetArchive($project, $file_name, $zip_file, $delay = 0) {
    try {
      sleep($delay);
      $http_client = new Client();
      $http_client->get("https://www.drupal.org/in-place-updates/$project/$file_name", ['sink' => $zip_file]);
    }
    catch (RequestException $exception) {
      $response = $exception->getResponse();
      if ($response && $response->getStatusCode() === 429) {
        $this->doGetArchive($project, $file_name, $zip_file, 10);
      }
      else {
        throw $exception;
      }
    }
  }

  /**
   * Assert an upgrade succeeded.
   *
   * @param string $from_version
   *   The version from which to upgrade.
   * @param string $to_version
   *   The version to which to upgrade.
   *
   * @throws \Behat\Mink\Exception\ExpectationException
   * @throws \Behat\Mink\Exception\ResponseTextException
   */
  public function assertCoreUpgradeSuccess($from_version, $to_version) {
    // Assert files slated for deletion still exist.
    foreach ($this->getDeletions('drupal', $from_version, $to_version) as $deletion) {
      $this->assertFileExists($this->getWorkspaceDirectory() . DIRECTORY_SEPARATOR . $deletion);
    }

    // Update the site.
    $assert = $this->visit("/test_automatic_updates/in-place-update/drupal/core/$from_version/$to_version")
      ->assertSession();
    $assert->statusCodeEquals(200);
    $this->assertDrupalVisit();

    // Assert that the update worked.
    $finder = new Finder();
    $finder->files()->in($this->getWorkspaceDirectory())->path('core/lib/Drupal.php');
    $finder->contains("/const VERSION = '$to_version'/");
    $this->assertTrue($finder->hasResults(), "Expected version $to_version does not exist in {$this->getWorkspaceDirectory()}/core/lib/Drupal.php");
    $assert->pageTextContains('Update successful');
    $this->visit('/admin/reports/status');
    $assert->pageTextContains("Drupal Version $to_version");

    // Assert files slated for deletion are now gone.
    foreach ($this->getDeletions('drupal', $from_version, $to_version) as $deletion) {
      $this->assertFileNotExists($this->getWorkspaceDirectory() . DIRECTORY_SEPARATOR . $deletion);
    }

    // Validate that all DB updates are processed.
    $this->visit('/update.php/selection');
    $assert->pageTextContains('No pending updates.');
  }

  /**
   * Assert an upgraded failed and was handle appropriately.
   *
   * @param string $from_version
   *   The version from which to upgrade.
   * @param string $to_version
   *   The version to which to upgrade.
   *
   * @throws \Behat\Mink\Exception\ResponseTextException
   */
  public function assertCoreUpgradeFailed($from_version, $to_version) {
    // Assert files slated for deletion still exist.
    foreach ($this->getDeletions('drupal', $from_version, $to_version) as $deletion) {
      $this->assertFileExists($this->getWorkspaceDirectory() . DIRECTORY_SEPARATOR . $deletion);
    }

    // Update the site.
    $assert = $this->visit("/test_automatic_updates/in-place-update/drupal/core/$from_version/$to_version")
      ->assertSession();
    $assert->statusCodeEquals(200);

    // Assert that the update failed.
    $finder = new Finder();
    $finder->files()->in($this->getWorkspaceDirectory())->path('core/lib/Drupal.php');
    $finder->contains("/const VERSION = '$from_version'/");
    $this->assertTrue($finder->hasResults(), "Expected version $from_version does not exist in {$this->getWorkspaceDirectory()}/core/lib/Drupal.php");
    $assert->pageTextContains('Update Failed');
    $this->visit('/admin/reports/status');
    $assert->pageTextContains("Drupal Version $from_version");

    // Assert files slated for deletion are restored.
    foreach ($this->getDeletions('drupal', $from_version, $to_version) as $deletion) {
      $this->assertFileExists($this->getWorkspaceDirectory() . DIRECTORY_SEPARATOR . $deletion);
    }
  }

}
