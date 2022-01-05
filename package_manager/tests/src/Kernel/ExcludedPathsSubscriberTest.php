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
class ExcludedPathsSubscriberTest extends PackageManagerKernelTestBase {

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
   *
   * @see \Drupal\Tests\package_manager\Functional\ExcludedPathsTest
   */
  public function testSqliteDatabaseExcluded(string $database, array $expected_exclusions): void {
    $connection = $this->prophesize(Connection::class);
    $connection->driver()->willReturn('sqlite');
    $connection->getConnectionOptions()->willReturn(['database' => $database]);

    $subscriber = new ExcludedPathsSubscriber(
      'sites/default',
      $this->container->get('package_manager.symfony_file_system'),
      $this->container->get('stream_wrapper_manager'),
      $connection->reveal(),
      $this->container->get('package_manager.path_locator')
    );

    $event = new PreCreateEvent($this->createStage());
    $subscriber->ignoreCommonPaths($event);
    // All of the expected exclusions should be flagged.
    $this->assertEmpty(array_diff($expected_exclusions, $event->getExcludedPaths()));
  }

}
