<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\Component\Datetime\Time;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Exception\StageException;

/**
 * @coversDefaultClass \Drupal\package_manager\Stage
 *
 * @group package_manager
 */
class StageTest extends PackageManagerKernelTestBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);

    $container->getDefinition('datetime.time')
      ->setClass(TestTime::class);
  }

  /**
   * @covers ::getStageDirectory
   * @covers ::getStagingRoot
   */
  public function testGetStageDirectory(): void {
    $this->container->get('module_installer')->install(['system']);
    // Ensure we have an up-to-date-container.
    $this->container = $this->container->get('kernel')->getContainer();

    // Ensure that a site ID was generated.
    // @see system_install()
    $site_id = $this->config('system.site')->get('uuid');
    $this->assertNotEmpty($site_id);

    $stage = $this->createStage();
    $id = $stage->create();
    $this->assertStringEndsWith("/.package_manager$site_id/$id", $stage->getStageDirectory());
    $stage->destroy();

    $stage = $this->createStage();
    $another_id = $stage->create();
    // The new stage ID should be unique, but the parent directory should be
    // unchanged.
    $this->assertNotSame($id, $another_id);
    $this->assertStringEndsWith("/.package_manager$site_id/$another_id", $stage->getStageDirectory());
  }

  /**
   * @covers ::getStageDirectory
   */
  public function testUncreatedGetStageDirectory(): void {
    $this->expectException('LogicException');
    $this->expectExceptionMessage('Drupal\package_manager\Stage::getStageDirectory() cannot be called because the stage has not been created or claimed.');
    $this->createStage()->getStageDirectory();
  }

  /**
   * Data provider for ::testDestroyDuringApply().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerDestroyDuringApply(): array {
    return [
      'force destroy, not stale' => [TRUE, 1, TRUE],
      'regular destroy, not stale' => [FALSE, 1, TRUE],
      'force destroy, stale' => [TRUE, 7200, FALSE],
      'regular destroy, stale' => [FALSE, 7200, FALSE],
    ];
  }

  /**
   * Tests destroying a stage while applying it.
   *
   * @param bool $force
   *   Whether or not the stage should be force destroyed.
   * @param int $time_offset
   *   How many simulated seconds should have elapsed between the PreApplyEvent
   *   being dispatched and the attempt to destroy the stage.
   * @param bool $expect_exception
   *   Whether or not destroying the stage will raise an exception.
   *
   * @dataProvider providerDestroyDuringApply
   */
  public function testDestroyDuringApply(bool $force, int $time_offset, bool $expect_exception): void {
    $listener = function (PreApplyEvent $event) use ($force, $time_offset): void {
      // Simulate that a certain amount of time has passed since we started
      // applying staged changes. After a point, it should be possible to
      // destroy the stage even if it hasn't finished.
      TestTime::$offset = $time_offset;

      // No real-life event subscriber should try to destroy the stage while
      // handling another event. The only reason we're doing it here is to
      // simulate an attempt to destroy the stage while it's being applied, for
      // testing purposes.
      $event->getStage()->destroy($force);
    };
    $this->container->get('event_dispatcher')
      ->addListener(PreApplyEvent::class, $listener);

    $stage = $this->createStage();
    $stage->create();
    if ($expect_exception) {
      $this->expectException(StageException::class);
      $this->expectExceptionMessage('Cannot destroy the staging area while it is being applied to the active directory.');
    }
    $stage->apply();
  }

}

/**
 * A test-only implementation of the time service.
 */
class TestTime extends Time {

  /**
   * An offset to add to the request time.
   *
   * @var int
   */
  public static $offset = 0;

  /**
   * {@inheritdoc}
   */
  public function getRequestTime() {
    return parent::getRequestTime() + static::$offset;
  }

}
