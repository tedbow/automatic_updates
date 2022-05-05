<?php

namespace Drupal\package_manager_test_fixture\EventSubscriber;

use Drupal\Core\State\StateInterface;
use Drupal\package_manager\Event\PostRequireEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Defines an event subscriber which copies certain files into the staging area.
 *
 * This is most useful in conjunction with package_manager_bypass, which quietly
 * turns all Composer Stager operations into no-ops. In such cases, no staging
 * area will be physically created, but if a test needs to simulate certain
 * conditions in a staging area without actually staging the active code base,
 * this event subscriber is the way to do it.
 */
class FixtureStager implements EventSubscriberInterface {

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The Symfony file system service.
   *
   * @var \Symfony\Component\Filesystem\Filesystem
   */
  protected $fileSystem;

  /**
   * Constructs a FixtureStager.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Symfony\Component\Filesystem\Filesystem $file_system
   *   The Symfony file system service.
   */
  public function __construct(StateInterface $state, Filesystem $file_system) {
    $this->state = $state;
    $this->fileSystem = $file_system;
  }

  /**
   * Copies files from a fixture into the staging area.
   *
   * Tests which use this functionality are responsible for cleaning up the
   * staging area.
   *
   * @param \Drupal\package_manager\Event\PostRequireEvent $event
   *   The event object.
   *
   * @see \Drupal\Tests\automatic_updates\Functional\AutomaticUpdatesFunctionalTestBase::tearDown()
   */
  public function copyFilesFromFixture(PostRequireEvent $event): void {
    [$fixturePath, $changeLock] = $this->state->get(static::class);

    if ($fixturePath && is_dir($fixturePath)) {
      $destination = $event->getStage()->getStageDirectory();

      $this->fileSystem->mirror($fixturePath, $destination, NULL, [
        'override' => TRUE,
        'delete' => TRUE,
      ]);

      // Modify the lock file in the staging area, to simulate that a package
      // was added, updated, or removed. Otherwise, tests must remember to
      // disable the lock file validator.
      // @see \Drupal\package_manager\Validator\LockFileValidator
      $lock = $destination . '/composer.lock';
      if ($changeLock && file_exists($lock)) {
        $data = file_get_contents($lock);
        $data = json_decode($data);
        $data->_time = microtime();
        file_put_contents($lock, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      PostRequireEvent::class => 'copyFilesFromFixture',
    ];
  }

  /**
   * Sets the path of the fixture to copy into the staging area.
   *
   * @param string $path
   *   The path of the fixture to copy into the staging area.
   * @param bool $change_lock
   *   (optional) Whether to change the lock file, in order to simulate the
   *   addition, updating, or removal of a package. Defaults to TRUE.
   */
  public static function setFixturePath(string $path, bool $change_lock = TRUE): void {
    \Drupal::state()->set(static::class, [$path, $change_lock]);
  }

}
