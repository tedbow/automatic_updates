<?php

namespace Drupal\Tests\automatic_updates\Functional;

use Drupal\automatic_updates\PathLocator;
use Drupal\automatic_updates\Updater;
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
  protected static $modules = ['automatic_updates_test', 'update_test'];

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

    /** @var \Drupal\automatic_updates\PathLocator|\Prophecy\Prophecy\ObjectProphecy $locator */
    $locator = $this->prophesize(PathLocator::class);
    $locator->getActiveDirectory()->willReturn(__DIR__ . '/../../fixtures/fake-site');
    $locator->getStageDirectory()->willReturn($stage_dir);
    $locator->getProjectRoot()->willReturn($this->getDrupalRoot());

    $updater = new Updater(
      $this->container->get('state'),
      $this->container->get('string_translation'),
      $this->container->get('automatic_updates.beginner'),
      $this->container->get('automatic_updates.stager'),
      $this->container->get('automatic_updates.cleaner'),
      $this->container->get('automatic_updates.committer'),
      $this->container->get('event_dispatcher'),
      $locator->reveal()
    );

    $settings = Settings::getAll();
    $settings['file_public_path'] = 'files/public';
    $settings['file_private_path'] = 'files/private';
    new Settings($settings);

    // Updater::begin() will trigger update validators, such as
    // \Drupal\automatic_updates\Event\UpdateVersionSubscriber, that need to
    // fetch release metadata. We need to ensure that those HTTP request(s)
    // succeed, so set them up to point to our fake release metadata.
    $this->config('update_test.settings')
      ->set('xml_map', [
        'drupal' => '0.0',
      ])
      ->save();
    $this->config('update.settings')
      ->set('fetch.url', $this->baseUrl . '/automatic-update-test')
      ->save();
    $this->config('update_test.settings')
      ->set('system_info.#all.version', '9.8.0')
      ->save();

    $updater->begin(['drupal' => '9.8.1']);
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
