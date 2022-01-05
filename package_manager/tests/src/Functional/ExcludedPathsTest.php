<?php

namespace Drupal\Tests\package_manager\Functional;

use Drupal\Core\Database\Connection;
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

    $path_locator = $this->prophesize(PathLocator::class);
    $path_locator->getActiveDirectory()->willReturn($active_dir);

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

    $stage = new class(
      $path_locator->reveal(),
      $this->container->get('package_manager.beginner'),
      $this->container->get('package_manager.stager'),
      $this->container->get('package_manager.committer'),
      $this->container->get('file_system'),
      $this->container->get('event_dispatcher'),
      $this->container->get('tempstore.shared'),
    ) extends Stage {

      /**
       * The directory where staging areas will be created.
       *
       * @var string
       */
      public static $stagingRoot;

      /**
       * {@inheritdoc}
       */
      protected static function getStagingRoot(): string {
        return static::$stagingRoot;
      }

    };
    $stage::$stagingRoot = $this->siteDirectory . '/stage';
    $stage_dir = $stage::$stagingRoot . DIRECTORY_SEPARATOR . $stage->create();
    $this->assertDirectoryExists($stage_dir);

    $ignore = [
      'sites/simpletest',
      'vendor/.htaccess',
      'vendor/web.config',
      "$site_path/files/ignore.txt",
      'private/ignore.txt',
      "$site_path/settings.php",
      "$site_path/settings.local.php",
      "$site_path/services.yml",
      // SQLite databases and their support files should always be ignored.
      "$site_path/db.sqlite",
      "$site_path/db.sqlite-shm",
      "$site_path/db.sqlite-wal",
      // Default site-specific settings files should be ignored.
      'sites/default/settings.php',
      'sites/default/settings.local.php',
      'sites/default/services.yml',
    ];
    foreach ($ignore as $path) {
      $this->assertFileExists("$active_dir/$path");
      $this->assertFileDoesNotExist("$stage_dir/$path");
    }
    // A non-excluded file in the default site directory should be staged.
    $this->assertFileExists("$stage_dir/sites/default/stage.txt");

    // A new file added to the staging area in an excluded directory, should not
    // be copied to the active directory.
    $file = "$stage_dir/sites/default/no-copy.txt";
    touch($file);
    $this->assertFileExists($file);
    $stage->apply();
    $this->assertFileDoesNotExist("$active_dir/sites/default/no-copy.txt");

    // The ignored files should still be in the active directory.
    foreach ($ignore as $path) {
      $this->assertFileExists("$active_dir/$path");
    }
  }

}
