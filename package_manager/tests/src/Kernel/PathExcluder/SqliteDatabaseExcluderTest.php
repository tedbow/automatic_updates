<?php

namespace Drupal\Tests\package_manager\Kernel\PathExcluder;

use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\PathExcluder\SqliteDatabaseExcluder;
use Drupal\Tests\package_manager\Kernel\PackageManagerKernelTestBase;

/**
 * @covers \Drupal\package_manager\PathExcluder\SqliteDatabaseExcluder
 *
 * @group package_manager
 */
class SqliteDatabaseExcluderTest extends PackageManagerKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // In this test, we want to disable the lock file validator because, even
    // though both the active and stage directories will have a valid lock file,
    // this validator will complain because they don't differ at all.
    $this->disableValidators[] = 'package_manager.validator.lock_file';
    parent::setUp();
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);

    $container->getDefinition('package_manager.sqlite_excluder')
      ->setClass(TestSqliteDatabaseExcluder::class);
  }

  /**
   * Mocks a SQLite database connection for the event subscriber.
   *
   * @param array $connection_options
   *   The connection options for the mocked database connection.
   */
  private function mockDatabase(array $connection_options): void {
    $database = $this->prophesize(Connection::class);
    $database->driver()->willReturn('sqlite');
    $database->getConnectionOptions()->willReturn($connection_options);

    $this->container->get('package_manager.sqlite_excluder')
      ->database = $database->reveal();
  }

  /**
   * Tests that SQLite database files are excluded from staging operations.
   */
  public function testSqliteDatabaseFilesExcluded(): void {
    // In this test, we want to perform the actual staging operations so that we
    // can be sure that files are staged as expected.
    $this->disableModules(['package_manager_bypass']);
    // Ensure we have an up-to-date container.
    $this->container = $this->container->get('kernel')->getContainer();

    $this->createTestProject();
    $active_dir = $this->container->get('package_manager.path_locator')
      ->getProjectRoot();

    // Mock a SQLite database connection to a file in the active directory. The
    // file should not be staged.
    $this->mockDatabase([
      'database' => 'sites/example.com/db.sqlite',
    ]);

    $stage = $this->createStage();
    $stage->create();
    $stage_dir = $stage->getStageDirectory();

    $ignored = [
      "sites/example.com/db.sqlite",
      "sites/example.com/db.sqlite-shm",
      "sites/example.com/db.sqlite-wal",
    ];
    foreach ($ignored as $path) {
      $this->assertFileExists("$active_dir/$path");
      $this->assertFileDoesNotExist("$stage_dir/$path");
    }

    $stage->apply();
    // The ignored files should still be in the active directory.
    foreach ($ignored as $path) {
      $this->assertFileExists("$active_dir/$path");
    }
  }

  /**
   * Data provider for ::testPathProcessing().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerPathProcessing(): array {
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
   * Tests SQLite database path processing.
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
   * @dataProvider providerPathProcessing
   */
  public function testPathProcessing(string $database_path, array $expected_exclusions): void {
    $this->mockDatabase([
      'database' => $database_path,
    ]);

    $event = new PreCreateEvent($this->createStage());
    // Invoke the event subscriber directly, so we can check that the database
    // was correctly excluded.
    $this->container->get('package_manager.sqlite_excluder')
      ->excludeDatabaseFiles($event);
    // All of the expected exclusions should be flagged.
    $this->assertEmpty(array_diff($expected_exclusions, $event->getExcludedPaths()));
  }

}

/**
 * A test-only version of the SQLite database excluder, to expose internals.
 */
class TestSqliteDatabaseExcluder extends SqliteDatabaseExcluder {

  /**
   * {@inheritdoc}
   */
  public $database;

}