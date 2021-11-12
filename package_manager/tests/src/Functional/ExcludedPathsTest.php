<?php

namespace Drupal\Tests\package_manager\Functional;

use Drupal\Core\Database\Driver\sqlite\Connection;
use Drupal\Core\Site\Settings;
use Drupal\package_manager\PathLocator;
use Drupal\package_manager\Stage;
use Drupal\Tests\BrowserTestBase;

/**
 * @covers \Drupal\package_manager\EventSubscriber\ExcludedPathsSubscriber
 *
 * @group package_manager
 */
class ExcludedPathsTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'package_manager',
    'package_manager_bypass',
  ];

  /**
   * {@inheritdoc}
   */
  protected function prepareSettings() {
    parent::prepareSettings();

    // Disable the filesystem permissions validator, since we cannot guarantee
    // that the current code base will be writable in all testing situations.
    // We test this validator functionally in Automatic Updates' build tests,
    // since those do give us control over the filesystem permissions.
    // @see \Drupal\Tests\automatic_updates\Build\CoreUpdateTest::assertReadOnlyFileSystemError()
    // @see \Drupal\Tests\package_manager\Kernel\WritableFileSystemValidatorTest
    $this->writeSettings([
      'settings' => [
        'package_manager_bypass_stager' => (object) [
          'value' => FALSE,
          'required' => TRUE,
        ],
        'package_manager_bypass_validators' => (object) [
          'value' => ['package_manager.validator.file_system'],
          'required' => TRUE,
        ],
      ],
    ]);
  }

  /**
   * Tests that certain paths are excluded from staging areas.
   */
  public function testExcludedPaths(): void {
    $active_dir = __DIR__ . '/../../fixtures/fake_site';
    $stage_dir = $this->siteDirectory . '/stage';

    $path_locator = $this->prophesize(PathLocator::class);
    $path_locator->getActiveDirectory()->willReturn($active_dir);
    $path_locator->getStageDirectory()->willReturn($stage_dir);

    $site_path = 'sites/example.com';

    // Ensure that we are using directories within the fake site fixture for
    // public and private files.
    $settings = Settings::getAll();
    $settings['file_public_path'] = "$site_path/files";
    $settings['file_private_path'] = 'private';
    new Settings($settings);

    /** @var \Drupal\package_manager\EventSubscriber\ExcludedPathsSubscriber $subscriber */
    $subscriber = $this->container->get('package_manager.excluded_paths_subscriber');
    $reflector = new \ReflectionObject($subscriber);
    $property = $reflector->getProperty('sitePath');
    $property->setAccessible(TRUE);
    $property->setValue($subscriber, $site_path);

    // Mock a SQLite database connection to a file in the active directory. The
    // file should not be staged.
    $database = $this->prophesize(Connection::class);
    $database->driver()->willReturn('sqlite');
    $database->getConnectionOptions()->willReturn([
      'database' => $site_path . '/db.sqlite',
    ]);
    $property = $reflector->getProperty('database');
    $property->setAccessible(TRUE);
    $property->setValue($subscriber, $database->reveal());

    $stage = new Stage(
      $path_locator->reveal(),
      $this->container->get('package_manager.beginner'),
      $this->container->get('package_manager.stager'),
      $this->container->get('package_manager.committer'),
      $this->container->get('package_manager.cleaner'),
      $this->container->get('event_dispatcher'),
      $this->container->get('tempstore.shared'),
    );
    $stage->create();

    $this->assertDirectoryExists($stage_dir);
    $this->assertDirectoryNotExists("$stage_dir/sites/simpletest");
    $this->assertFileNotExists("$stage_dir/vendor/web.config");
    $this->assertDirectoryNotExists("$stage_dir/$site_path/files");
    $this->assertDirectoryNotExists("$stage_dir/private");
    $this->assertFileNotExists("$stage_dir/$site_path/settings.php");
    $this->assertFileNotExists("$stage_dir/$site_path/settings.local.php");
    $this->assertFileNotExists("$stage_dir/$site_path/services.yml");
    // SQLite databases and their support files should never be staged.
    $this->assertFileNotExists("$stage_dir/$site_path/db.sqlite");
    $this->assertFileNotExists("$stage_dir/$site_path/db.sqlite-shm");
    $this->assertFileNotExists("$stage_dir/$site_path/db.sqlite-wal");
    // Default site-specific settings files should never be staged.
    $this->assertFileNotExists("$stage_dir/sites/default/settings.php");
    $this->assertFileNotExists("$stage_dir/sites/default/settings.local.php");
    $this->assertFileNotExists("$stage_dir/sites/default/services.yml");
    // A non-excluded file in the default site directory should be staged.
    $this->assertFileExists("$stage_dir/sites/default/stage.txt");

    $files = [
      'sites/default/no-copy.txt',
      'web.config',
    ];
    foreach ($files as $file) {
      $file = "$stage_dir/$file";
      touch($file);
      $this->assertFileExists($file);
    }
    $stage->apply();
    foreach ($files as $file) {
      $this->assertFileNotExists("$active_dir/$file");
    }
  }

}
