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
    $fixturePath = $this->state->get(static::class);
    if ($fixturePath && is_dir($fixturePath)) {
      $this->fileSystem->mirror($fixturePath, $event->getStage()->getStageDirectory(), NULL, [
        'override' => TRUE,
        'delete' => TRUE,
      ]);
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
   */
  public static function setFixturePath(string $path): void {
    \Drupal::state()->set(static::class, $path);
  }

}
