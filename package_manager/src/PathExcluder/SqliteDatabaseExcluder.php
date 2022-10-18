<?php

namespace Drupal\package_manager\PathExcluder;

use Drupal\Core\Database\Connection;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\StageEvent;
use Drupal\package_manager\PathLocator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Excludes SQLite database files from staging operations.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
class SqliteDatabaseExcluder implements EventSubscriberInterface {

  use PathExclusionsTrait;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a SqliteDatabaseExcluder object.
   *
   * @param \Drupal\package_manager\PathLocator $path_locator
   *   The path locator service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(PathLocator $path_locator, Connection $database) {
    $this->pathLocator = $path_locator;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PreCreateEvent::class => 'excludeDatabaseFiles',
      PreApplyEvent::class => 'excludeDatabaseFiles',
    ];
  }

  /**
   * Excludes SQLite database files from staging operations.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   */
  public function excludeDatabaseFiles(StageEvent $event): void {
    // If the database is SQLite, it might be located in the active directory
    // and we should ignore it. Always treat it as relative to the project root.
    if ($this->database->driver() === 'sqlite') {
      $options = $this->database->getConnectionOptions();
      $this->excludeInProjectRoot($event, [
        $options['database'],
        $options['database'] . '-shm',
        $options['database'] . '-wal',
      ]);
    }
  }

}
