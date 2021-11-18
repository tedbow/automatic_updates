<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\package_manager\Event\ErrorEventInterface;
use Drupal\package_manager\Event\PostApplyEvent;
use Drupal\package_manager\Event\PostCreateEvent;
use Drupal\package_manager\Event\PostDestroyEvent;
use Drupal\package_manager\Event\PostRequireEvent;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreDestroyEvent;
use Drupal\package_manager\Event\PreRequireEvent;
use Drupal\package_manager\Event\StageEvent;
use Drupal\package_manager\Event\WarningEventInterface;
use Drupal\package_manager\Stage;
use Drupal\package_manager\StageException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Tests that the staging area fires events during its lifecycle.
 *
 * @covers \Drupal\package_manager\Event\StageEvent
 *
 * @group package_manager
 */
class StageEventsTest extends PackageManagerKernelTestBase implements EventSubscriberInterface {

  /**
   * The events that were fired, in the order they were fired.
   *
   * @var string[]
   */
  private $events = [];

  /**
   * The stage under test.
   *
   * @var \Drupal\package_manager\Stage
   */
  private $stage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->stage = new Stage(
      $this->container->get('package_manager.path_locator'),
      $this->container->get('package_manager.beginner'),
      $this->container->get('package_manager.stager'),
      $this->container->get('package_manager.committer'),
      $this->container->get('package_manager.cleaner'),
      $this->container->get('event_dispatcher'),
      $this->container->get('tempstore.shared')
    );
  }

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
   * @param \Drupal\package_manager\Event\PreOperationStageEvent|\Drupal\package_manager\Event\PostOperationStageEvent $event
   *   The event object.
   */
  public function handleEvent(StageEvent $event): void {
    array_push($this->events, get_class($event));

    // The event should have a reference to the stage which fired it.
    $this->assertSame($event->getStage(), $this->stage);
  }

  /**
   * Tests that the staging area fires life cycle events in a specific order.
   */
  public function testEvents(): void {
    $this->container->get('event_dispatcher')->addSubscriber($this);

    $this->stage->create();
    $this->stage->require(['ext-json:*']);
    $this->stage->apply();
    $this->stage->destroy();

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
   * Data provider for ::testValidationResults().
   *
   * @return string[][]
   *   Sets of arguments to pass to the test method.
   */
  public function providerValidationResults(): array {
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
   * Tests that an exception is thrown if an event has validation results.
   *
   * @param string $event_class
   *   The event class to test.
   *
   * @dataProvider providerValidationResults
   */
  public function testValidationResults(string $event_class): void {
    // Set up an event listener which will only flag an error for the event
    // class under test.
    $handler = function (StageEvent $event) use ($event_class): void {
      if (get_class($event) === $event_class) {
        if ($event instanceof ErrorEventInterface) {
          $event->addError([['Burn, baby, burn']]);
        }
        elseif ($event instanceof WarningEventInterface) {
          $event->addWarning(['Be careful about fires.']);
        }
      }
    };
    $this->container->get('event_dispatcher')
      ->addListener($event_class, $handler);

    try {
      $this->stage->create();
      $this->stage->require(['ext-json:*']);
      $this->stage->apply();
      $this->stage->destroy();

      $this->fail('Expected \Drupal\package_manager\StageException to be thrown.');
    }
    catch (StageException $e) {
      $this->assertCount(1, $e->getResults());
    }
  }

}
