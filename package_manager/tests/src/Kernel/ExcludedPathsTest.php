<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\Core\Database\Connection;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\EventSubscriber\ExcludedPathsSubscriber;

/**
 * @covers \Drupal\package_manager\EventSubscriber\ExcludedPathsSubscriber
 *
 * @group package_manager
 */
class ExcludedPathsTest extends PackageManagerKernelTestBase {

  /**
   * The mocked SQLite database connection.
   *
   * @var \Drupal\Core\Database\Connection|\Prophecy\Prophecy\ObjectProphecy
   */
  private $mockDatabase;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Normally, package_manager_bypass will disable all the actual staging
    // operations. In this case, we want to perform them so that we can be sure
    // that files are staged as expected.
    $this->setSetting('package_manager_bypass_stager', FALSE);
    // The private stream wrapper is only registered if this setting is set.
    // @see \Drupal\Core\CoreServiceProvider::register()
    $this->setSetting('file_private_path', 'private');

    // Rebuild the container to make the new settings take effect.
    $kernel = $this->container->get('kernel');
    $kernel->rebuildContainer();
    $this->container = $kernel->getContainer();

    // Mock a SQLite database connection so we can test that the subscriber will
    // exclude the database files.
    $this->mockDatabase = $this->prophesize(Connection::class);
    $this->mockDatabase->driver()->willReturn('sqlite');
  }

  /**
   * Tests that certain paths are excluded from staging operations.
   */
  public function testExcludedPaths(): void {
    $this->createTestProject();
    $active_dir = $this->container->get('package_manager.path_locator')
      ->getActiveDirectory();

    $site_path = 'sites/example.com';
    // Ensure that we are using directories within the fake site fixture for
    // public and private files.
    $this->setSetting('file_public_path', "$site_path/files");

    // Mock a SQLite database connection to a file in the active directory. The
    // file should not be staged.
    $this->mockDatabase->getConnectionOptions()->willReturn([
      'database' => $site_path . '/db.sqlite',
    ]);
    $this->setUpSubscriber($site_path);

    $stage = $this->createStage();
    $stage->create();
    $stage_dir = $stage->getStageDirectory();

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
      // No git directories should be staged.
      '.git/ignore.txt',
      'modules/example/.git/ignore.txt',
    ];
    foreach ($ignore as $path) {
      $this->assertFileExists("$active_dir/$path");
      $this->assertFileDoesNotExist("$stage_dir/$path");
    }
    // A non-excluded file in the default site directory should be staged.
    $this->assertFileExists("$stage_dir/sites/default/stage.txt");
    // Regular module files should be staged.
    $this->assertFileExists("$stage_dir/modules/example/example.info.yml");
    // Files that start with .git, but aren't actually .git, should be staged.
    $this->assertFileExists("$stage_dir/.gitignore");

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

  /**
   * Data provider for ::testSqliteDatabaseExcluded().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerSqliteDatabaseExcluded(): array {
    $drupal_root = $this->getDrupalRoot();

    return [
      'relative path, in site directory' => [
        'sites/example.com/db.sqlite',
        [
          'sites/example.com/db.sqlite',
          'sites/example.com/db.sqlite-shm',
          'sites/example.com/db.sqlite-wal',
        ],
      ],
      'relative path, at root' => [
        'db.sqlite',
        [
          'db.sqlite',
          'db.sqlite-shm',
          'db.sqlite-wal',
        ],
      ],
      'absolute path, in site directory' => [
        $drupal_root . '/sites/example.com/db.sqlite',
        [
          'sites/example.com/db.sqlite',
          'sites/example.com/db.sqlite-shm',
          'sites/example.com/db.sqlite-wal',
        ],
      ],
      'absolute path, at root' => [
        $drupal_root . '/db.sqlite',
        [
          'db.sqlite',
          'db.sqlite-shm',
          'db.sqlite-wal',
        ],
      ],
    ];
  }

  /**
   * Tests that SQLite database paths are excluded from the staging area.
   *
   * The exclusion of SQLite databases from the staging area is functionally
   * tested by \Drupal\Tests\package_manager\Functional\ExcludedPathsTest. The
   * purpose of this test is to ensure that SQLite database paths are processed
   * properly (e.g., converting an absolute path to a relative path) before
   * being flagged for exclusion.
   *
   * @param string $database
   *   The path of the SQLite database, as set in the database connection
   *   options.
   * @param string[] $expected_exclusions
   *   The database paths which should be flagged for exclusion.
   *
   * @dataProvider providerSqliteDatabaseExcluded
   */
  public function testSqliteDatabaseExcluded(string $database, array $expected_exclusions): void {
    $this->mockDatabase->getConnectionOptions()->willReturn([
      'database' => $database,
    ]);

    $event = new PreCreateEvent($this->createStage());
    $this->setUpSubscriber();
    $this->container->get('package_manager.excluded_paths_subscriber')->ignoreCommonPaths($event);
    // All of the expected exclusions should be flagged.
    $this->assertEmpty(array_diff($expected_exclusions, $event->getExcludedPaths()));
  }

  /**
   * Sets up the event subscriber with a mocked database and site path.
   *
   * @param string $site_path
   *   (optional) The site path. Defaults to 'sites/default'.
   */
  private function setUpSubscriber(string $site_path = 'sites/default'): void {
    $this->container->set('package_manager.excluded_paths_subscriber', new ExcludedPathsSubscriber(
      $site_path,
      $this->container->get('package_manager.symfony_file_system'),
      $this->container->get('stream_wrapper_manager'),
      $this->mockDatabase->reveal(),
      $this->container->get('package_manager.path_locator')
    ));
  }

}
