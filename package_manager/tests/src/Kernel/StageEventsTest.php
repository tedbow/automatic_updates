<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\package_manager\Event\PostApplyEvent;
use Drupal\package_manager\Event\PostCreateEvent;
use Drupal\package_manager\Event\PostDestroyEvent;
use Drupal\package_manager\Event\PostRequireEvent;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreDestroyEvent;
use Drupal\package_manager\Event\PreRequireEvent;
use Drupal\package_manager\Event\StageEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Tests that the staging area fires events during its lifecycle.
 *
 * @group package_manager
 */
class StageEventsTest extends KernelTestBase implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'package_manager',
    'package_manager_bypass',
  ];

  /**
   * The events that were fired, in the order they were fired.
   *
   * @var string[]
   */
  private $events = [];

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      PreCreateEvent::class => 'handleEvent',
      PostCreateEvent::class => 'handleEvent',
      PreRequireEvent::class => 'handleEvent',
      PostRequireEvent::class => 'handleEvent',
      PreApplyEvent::class => 'handleEvent',
      PostApplyEvent::class => 'handleEvent',
      PreDestroyEvent::class => 'handleEvent',
      PostDestroyEvent::class => 'handleEvent',
    ];
  }

  /**
   * Handles a staging area life cycle event.
   *
   * @param \Drupal\package_manager\Event\StageEvent $event
   *   The event object.
   */
  public function handleEvent(StageEvent $event): void {
    array_push($this->events, get_class($event));
  }

  /**
   * Tests that the staging area fires life cycle events in a specific order.
   */
  public function testEvents(): void {
    $this->container->get('event_dispatcher')->addSubscriber($this);

    $stage = $this->container->get('package_manager.stage');
    $stage->create();
    $stage->require(['ext-json:*']);
    $stage->apply();
    $stage->destroy();

    $this->assertSame($this->events, [
      PreCreateEvent::class,
      PostCreateEvent::class,
      PreRequireEvent::class,
      PostRequireEvent::class,
      PreApplyEvent::class,
      PostApplyEvent::class,
      PreDestroyEvent::class,
      PostDestroyEvent::class,
    ]);
  }

}
