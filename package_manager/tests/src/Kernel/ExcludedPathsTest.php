<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\EventSubscriber\ExcludedPathsSubscriber;

/**
 * @covers \Drupal\package_manager\EventSubscriber\ExcludedPathsSubscriber
 *
 * @group package_manager
 */
class ExcludedPathsTest extends PackageManagerKernelTestBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);

    $container->getDefinition('package_manager.excluded_paths_subscriber')
      ->setClass(TestExcludedPathsSubscriber::class);
  }

  /**
   * Tests that certain paths are excluded from staging operations.
   */
  public function testExcludedPaths(): void {
    // The private stream wrapper is only registered if this setting is set.
    // @see \Drupal\Core\CoreServiceProvider::register()
    $this->setSetting('file_private_path', 'private');
    // In this test, we want to perform the actual staging operations so that we
    // can be sure that files are staged as expected. This will also rebuild
    // the container, enabling the private stream wrapper.
    $this->container->get('module_installer')->uninstall([
      'package_manager_bypass',
    ]);
    // Ensure we have an up-to-date container.
    $this->container = $this->container->get('kernel')->getContainer();

    $this->createTestProject();
    $active_dir = $this->container->get('package_manager.path_locator')
      ->getActiveDirectory();

    $site_path = 'sites/example.com';
    // Ensure that we are using directories within the fake site fixture for
    // public and private files.
    $this->setSetting('file_public_path', "$site_path/files");

    // Mock a SQLite database connection to a file in the active directory. The
    // file should not be staged.
    $database = $this->prophesize(Connection::class);
    $database->driver()->willReturn('sqlite');
    $database->getConnectionOptions()->willReturn([
      'database' => $site_path . '/db.sqlite',
    ]);

    // Update the event subscriber's dependencies.
    /** @var \Drupal\Tests\package_manager\Kernel\TestExcludedPathsSubscriber $subscriber */
    $subscriber = $this->container->get('package_manager.excluded_paths_subscriber');
    $subscriber->sitePath = $site_path;
    $subscriber->database = $database->reveal();

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
   * This test ensures that SQLite database paths are processed properly (e.g.,
   * converting an absolute path to a relative path) before being flagged for
   * exclusion.
   *
   * @param string $database_path
   *   The path of the SQLite database, as set in the database connection
   *   options.
   * @param string[] $expected_exclusions
   *   The database paths which should be flagged for exclusion.
   *
   * @dataProvider providerSqliteDatabaseExcluded
   */
  public function testSqliteDatabaseExcluded(string $database_path, array $expected_exclusions): void {
    $database = $this->prophesize(Connection::class);
    $database->driver()->willReturn('sqlite');
    $database->getConnectionOptions()->willReturn([
      'database' => $database_path,
    ]);

    // Update the event subscriber to use the mocked database.
    /** @var \Drupal\Tests\package_manager\Kernel\TestExcludedPathsSubscriber $subscriber */
    $subscriber = $this->container->get('package_manager.excluded_paths_subscriber');
    $subscriber->database = $database->reveal();

    $event = new PreCreateEvent($this->createStage());
    // Invoke the event subscriber directly, so we can check that the database
    // was correctly excluded.
    $subscriber->ignoreCommonPaths($event);
    // All of the expected exclusions should be flagged.
    $this->assertEmpty(array_diff($expected_exclusions, $event->getExcludedPaths()));
  }

  /**
   * Tests that unreadable directories are ignored by the event subscriber.
   */
  public function testUnreadableDirectoriesAreIgnored(): void {
    $this->createTestProject();
    $active_dir = $this->container->get('package_manager.path_locator')
      ->getActiveDirectory();

    // Create an unreadable directory within the active directory, which will
    // raise an exception as the event subscriber tries to scan for .git
    // directories...unless unreadable directories are being ignored, as they
    // should be.
    $unreadable_dir = $active_dir . '/unreadable';
    mkdir($unreadable_dir, 0000);
    $this->assertDirectoryIsNotReadable($unreadable_dir);

    $this->createStage()->create();
  }

}

/**
 * A test-only version of the excluded paths event subscriber.
 */
class TestExcludedPathsSubscriber extends ExcludedPathsSubscriber {

  /**
   * {@inheritdoc}
   */
  public $sitePath;

  /**
   * {@inheritdoc}
   */
  public $database;

}
