<?php

declare(strict_types = 1);

namespace Drupal\package_manager\PathExcluder;

use Drupal\Core\Database\Connection;
use Drupal\package_manager\Event\CollectPathsToExcludeEvent;
use Drupal\package_manager\PathLocator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Excludes SQLite database files from stage operations.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
class SqliteDatabaseExcluder implements EventSubscriberInterface {

  use PathExclusionsTrait;

  /**
   * Constructs a SqliteDatabaseExcluder object.
   *
   * @param \Drupal\package_manager\PathLocator $path_locator
   *   The path locator service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(PathLocator $path_locator, protected Connection $database) {
    $this->pathLocator = $path_locator;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      CollectPathsToExcludeEvent::class => 'excludeDatabaseFiles',
    ];
  }

  /**
   * Excludes SQLite database files from stage operations.
   *
   * @param \Drupal\package_manager\Event\CollectPathsToExcludeEvent $event
   *   The event object.
   */
  public function excludeDatabaseFiles(CollectPathsToExcludeEvent $event): void {
    // If the database is SQLite, it might be located in the active directory
    // and we should exclude it. Always treat it as relative to the project root.
    if ($this->database->driver() === 'sqlite') {
      $options = $this->database->getConnectionOptions();
      // Nothing to exclude if the database lives outside the project root.
      if (str_starts_with($options['database'], '/') && !str_starts_with($options['database'], $this->pathLocator->getProjectRoot())) {
        return;
      }
      $this->excludeInProjectRoot($event, [
        $options['database'],
        $options['database'] . '-shm',
        $options['database'] . '-wal',
      ]);
    }
  }

}
