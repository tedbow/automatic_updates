<?php

namespace Drupal\Tests\automatic_updates\Build\QuickStart;

use Drupal\BuildTests\Framework\BuildTestBase;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Helper methods for using the quickstart feature of Drupal.
 *
 * @TODO: remove after https://www.drupal.org/project/drupal/issues/3082230.
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
