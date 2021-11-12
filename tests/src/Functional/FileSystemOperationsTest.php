<?php

namespace Drupal\Tests\automatic_updates\Functional;

use Drupal\package_manager\Cleaner;
use Drupal\automatic_updates\Updater;
use Drupal\Core\Site\Settings;
use Drupal\package_manager\PathLocator;

/**
 * Tests handling of files and directories during an update.
 *
 * @group automatic_updates
 */
class FileSystemOperationsTest extends AutomaticUpdatesFunctionalTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The updater service under test.
   *
   * @var \Drupal\automatic_updates\Updater
   */
  private $updater;

  /**
   * The full path of the staging directory.
   *
   * @var string
   */
  protected $stageDir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create a mocked path locator that uses the fake site fixture as its
    // active directory, and has a staging area within the site directory for
    // this test.
    $drupal_root = $this->getDrupalRoot();
    /** @var \Drupal\package_manager\PathLocator|\Prophecy\Prophecy\ObjectProphecy $locator */
    $locator = $this->prophesize(PathLocator::class);
    $locator->getActiveDirectory()->willReturn(__DIR__ . '/../../fixtures/fake-site');
    $this->stageDir = implode(DIRECTORY_SEPARATOR, [
      $drupal_root,
      $this->siteDirectory,
      'stage',
    ]);
    $locator->getStageDirectory()->willReturn($this->stageDir);
    $locator->getProjectRoot()->willReturn($drupal_root);
    $locator->getWebRoot()->willReturn('');

    // Create a cleaner that uses 'sites/default' as its site path, since it
    // will otherwise default to the site path being used for the test site,
    // which doesn't exist in the fake site fixture.
    $cleaner = new Cleaner(
      $this->container->get('package_manager.file_system'),
      'sites/default',
      $locator->reveal()
    );

    $this->updater = new Updater(
      $this->container->get('state'),
      $locator->reveal(),
      $this->container->get('package_manager.beginner'),
      $this->container->get('package_manager.stager'),
      $this->container->get('package_manager.committer'),
      $cleaner,
      $this->container->get('event_dispatcher'),
      $this->container->get('tempstore.shared')
    );

    // Use the public and private files directories in the fake site fixture.
    $settings = Settings::getAll();
    $settings['file_public_path'] = 'files/public';
    $settings['file_private_path'] = 'files/private';
    new Settings($settings);

    // Updater::begin() will trigger update validators, such as
    // \Drupal\automatic_updates\Validator\UpdateVersionValidator, that need to
    // fetch release metadata. We need to ensure that those HTTP request(s)
    // succeed, so set them up to point to our fake release metadata.
    $this->setReleaseMetadata(__DIR__ . '/../../fixtures/release-history/drupal.9.8.1-security.xml');
    $this->setCoreVersion('9.8.0');

    $this->drupalLogin($this->rootUser);
    $this->checkForUpdates();
    $this->drupalLogout();
  }

  /**
   * Tests that certain files and directories are not staged.
   *
   * @covers \Drupal\automatic_updates\Updater::getExclusions
   */
  public function testExclusions(): void {
    $this->updater->begin(['drupal' => '9.8.1']);
    $this->assertFileDoesNotExist("$this->stageDir/sites/default/settings.php");
    $this->assertFileDoesNotExist("$this->stageDir/sites/default/settings.local.php");
    $this->assertFileDoesNotExist("$this->stageDir/sites/default/services.yml");
    // A file in sites/default, that isn't one of the site-specific settings
    // files, should be staged.
    $this->assertFileExists("$this->stageDir/sites/default/staged.txt");
    $this->assertDirectoryDoesNotExist("$this->stageDir/sites/simpletest");
    $this->assertDirectoryDoesNotExist("$this->stageDir/files/public");
    $this->assertDirectoryDoesNotExist("$this->stageDir/files/private");
    // A file that's in the general files directory, but not in the public or
    // private directories, should be staged.
    $this->assertFileExists("$this->stageDir/files/staged.txt");
  }

  /**
   * Tests that the staging directory is properly cleaned up.
   *
   * @covers \Drupal\automatic_updates\Cleaner
   */
  public function testClean(): void {
    $this->updater->begin(['drupal' => '9.8.1']);
    // Make the staged site directory read-only, so we can test that it will be
    // made writable on clean-up.
    $this->assertTrue(chmod("$this->stageDir/sites/default", 0400));
    $this->assertNotIsWritable("$this->stageDir/sites/default/staged.txt");
    // If the site directory is not writable, this will throw an exception.
    $this->updater->clean();
    $this->assertDirectoryDoesNotExist($this->stageDir);
  }

}
