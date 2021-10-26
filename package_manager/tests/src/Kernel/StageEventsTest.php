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
use Drupal\package_manager\StageException;
use Drupal\package_manager\ValidationResult;
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

    // Adding a warning to the event, should not trigger an exception.
    $result = ValidationResult::createWarning([
      'This is a public service announcement, this is only a test.',
    ]);
    $event->addValidationResult($result);
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

  /**
   * Data provider for ::testError().
   *
   * @return string[][]
   *   Sets of arguments to pass to the test method.
   */
  public function providerError(): array {
    return [
      [PreCreateEvent::class],
      [PostCreateEvent::class],
      [PreRequireEvent::class],
      [PostRequireEvent::class],
      [PreApplyEvent::class],
      [PostApplyEvent::class],
      [PreDestroyEvent::class],
      [PostDestroyEvent::class],
    ];
  }

  /**
   * Tests that an exception is thrown if an event collects an error.
   *
   * @param string $event_class
   *   The event class to test.
   *
   * @dataProvider providerError
   */
  public function testError(string $event_class): void {
    // Set up an event listener which will only flag an error for the event
    // class under test.
    $handler = function (StageEvent $event) use ($event_class): void {
      if (get_class($event) === $event_class) {
        $result = ValidationResult::createError(['Burn, baby, burn']);
        $event->addValidationResult($result);
      }
    };
    $this->container->get('event_dispatcher')
      ->addListener($event_class, $handler);

    try {
      $stage = $this->container->get('package_manager.stage');
      $stage->create();
      $stage->require(['ext-json:*']);
      $stage->apply();
      $stage->destroy();

      $this->fail('Expected \Drupal\package_manager\StageException to be thrown.');
    }
    catch (StageException $e) {
      $this->assertCount(1, $e->getResults());
    }
  }

}