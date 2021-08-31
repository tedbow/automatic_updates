<?php

namespace Drupal\Tests\automatic_updates\Functional;

use Drupal\Core\Site\Settings;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests exclusion of certain files and directories from the staging area.
 *
 * @group automatic_updates
 */
class ExclusionsTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests that certain files and directories are not staged.
   *
   * @covers \Drupal\automatic_updates\Updater::getExclusions
   */
  public function testExclusions(): void {
    $stage_dir = "$this->siteDirectory/stage";

    /** @var \Drupal\automatic_updates_test\TestUpdater $updater */
    $updater = $this->container->get('automatic_updates.updater');
    $updater->activeDirectory = __DIR__ . '/../../fixtures/fake-site';
    $updater->stageDirectory = $stage_dir;

    $settings = Settings::getAll();
    $settings['file_public_path'] = 'files/public';
    $settings['file_private_path'] = 'files/private';
    new Settings($settings);

    $updater->begin();
    $this->assertFileDoesNotExist("$stage_dir/sites/default/settings.php");
    $this->assertFileDoesNotExist("$stage_dir/sites/default/settings.local.php");
    $this->assertFileDoesNotExist("$stage_dir/sites/default/services.yml");
    // A file in sites/default, that isn't one of the site-specific settings
    // files, should be staged.
    $this->assertFileExists("$stage_dir/sites/default/staged.txt");
    $this->assertDirectoryDoesNotExist("$stage_dir/sites/simpletest");
    $this->assertDirectoryDoesNotExist("$stage_dir/files/public");
    $this->assertDirectoryDoesNotExist("$stage_dir/files/private");
    // A file that's in the general files directory, but not in the public or
    // private directories, should be staged.
    $this->assertFileExists("$stage_dir/files/staged.txt");
  }

}
