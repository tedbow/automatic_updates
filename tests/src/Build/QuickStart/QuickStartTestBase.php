<?php

namespace Drupal\Tests\automatic_updates\Build\QuickStart;

use Drupal\BuildTests\Framework\BuildTestBase;
use Drupal\Component\FileSystem\FileSystem as DrupalFilesystem;
use Drupal\Core\Archiver\Zip;
use GuzzleHttp\Client;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Helper methods for using the quickstart feature of Drupal.
 */
abstract class QuickStartTestBase extends BuildTestBase {

  /**
   * User name of the admin account generated during install.
   *
   * @var string
   */
  protected $adminUsername;

  /**
   * Password of the admin account generated during install.
   *
   * @var string
   */
  protected $adminPassword;

  /**
   * Install a Drupal site using the quick start feature.
   *
   * @param string $profile
   *   Drupal profile to install.
   * @param string $working_dir
   *   (optional) A working directory relative to the workspace, within which to
   *   execute the command. Defaults to the workspace directory.
   */
  public function installQuickStart($profile, $working_dir = NULL) {
    $finder = new PhpExecutableFinder();
    $process = $this->executeCommand($finder->find() . ' ./core/scripts/drupal install ' . $profile, $working_dir);
    $this->assertCommandSuccessful();
    $this->assertCommandOutputContains('Username:');
    preg_match('/Username: (.+)\vPassword: (.+)/', $process->getOutput(), $matches);
    $this->assertNotEmpty($this->adminUsername = $matches[1]);
    $this->assertNotEmpty($this->adminPassword = $matches[2]);
  }

  /**
   * Prepare core for testing.
   *
   * @param string $starting_version
   *   The starting version.
   */
  protected function installCore($starting_version) {
    // Get tarball of drupal core.
    $drupal_tarball = "drupal-$starting_version.zip";
    $destination = DrupalFileSystem::getOsTemporaryDirectory() . DIRECTORY_SEPARATOR . 'drupal-' . random_int(10000, 99999) . microtime(TRUE);
    $fs = new SymfonyFilesystem();
    $fs->mkdir($destination);
    $http_client = new Client();
    $http_client->get("https://ftp.drupal.org/files/projects/$drupal_tarball", ['sink' => $destination . DIRECTORY_SEPARATOR . $drupal_tarball]);
    $zip = new Zip($destination . DIRECTORY_SEPARATOR . $drupal_tarball);
    $zip->extract($destination);
    // Move the tarball codebase over to the test workspace.
    $finder = new Finder();
    $finder->files()
      ->ignoreUnreadableDirs()
      ->ignoreDotFiles(FALSE)
      ->in("$destination/drupal-$starting_version");
    $options = ['override' => TRUE, 'delete' => FALSE];
    $fs->mirror("$destination/drupal-$starting_version", $this->getWorkingPath(), $finder->getIterator(), $options);
    $fs->remove("$destination/drupal-$starting_version");
    // Copy in this module from the original code base.
    $finder = new Finder();
    $finder->files()
      ->ignoreUnreadableDirs()
      ->in($this->getDrupalRoot())
      ->path('automatic_updates');
    $this->copyCodebase($finder->getIterator());

    $fs->chmod($this->getWorkspaceDirectory() . '/sites/default', 0700);
    $this->installQuickStart('minimal');

    // Currently, this test has to use extension_discovery_scan_tests so we can
    // install test modules.
    $settings_php = $this->getWorkspaceDirectory() . '/sites/default/settings.php';
    $fs->chmod($this->getWorkspaceDirectory() . '/sites/default', 0755);
    $fs->chmod($settings_php, 0640);
    $fs->appendToFile($settings_php, '$settings[\'extension_discovery_scan_tests\'] = TRUE;' . PHP_EOL);

    // Log in so that we can install modules.
    $this->formLogin($this->adminUsername, $this->adminPassword);
    $this->moduleInstall('update');
    $this->moduleInstall('automatic_updates');
    $this->moduleInstall('test_automatic_updates');

    // Confirm we are running correct Drupal version.
    $finder = new Finder();
    $finder->files()->in($this->getWorkspaceDirectory())->path('core/lib/Drupal.php');
    $finder->contains("/const VERSION = '$starting_version'/");
    $this->assertTrue($finder->hasResults(), "Expected version $starting_version does not exist in {$this->getWorkspaceDirectory()}/core/lib/Drupal.php");

    // Assert that the site is functional after install.
    $this->visit();
    $this->assertDrupalVisit();
  }

  /**
   * Helper that uses Drupal's user/login form to log in.
   *
   * @param string $username
   *   Username.
   * @param string $password
   *   Password.
   * @param string $working_dir
   *   (optional) A working directory within which to login. Defaults to the
   *   workspace directory.
   */
  public function formLogin($username, $password, $working_dir = NULL) {
    $mink = $this->visit('/user/login', $working_dir);
    $this->assertEquals(200, $mink->getSession()->getStatusCode());
    $assert = $mink->assertSession();
    $assert->fieldExists('edit-name')->setValue($username);
    $assert->fieldExists('edit-pass')->setValue($password);
    $mink->getSession()->getPage()->findButton('Log in')->submit();
  }

}
