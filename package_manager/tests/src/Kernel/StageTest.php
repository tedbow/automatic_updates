<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\Component\Datetime\Time;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\ModuleUninstallValidatorException;
use Drupal\package_manager\Event\PostApplyEvent;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\StageEvent;
use Drupal\package_manager\Exception\ApplyFailedException;
use Drupal\package_manager\Exception\StageException;
use Drupal\package_manager_bypass\Committer;
use PhpTuf\ComposerStager\Domain\Exception\InvalidArgumentException;
use PhpTuf\ComposerStager\Domain\Exception\PreconditionException;
use Drupal\package_manager_bypass\Beginner;
use PhpTuf\ComposerStager\Domain\Service\Precondition\PreconditionInterface;

/**
 * @coversDefaultClass \Drupal\package_manager\Stage
 *
 * @covers \Drupal\package_manager\PackageManagerUninstallValidator
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

    // Since this test adds arbitrary event listeners that aren't services, we
    // need to ensure they will persist even if the container is rebuilt when
    // staged changes are applied.
    $container->getDefinition('event_dispatcher')->addTag('persist');
  }

  /**
   * @covers ::getStageDirectory
   */
  public function testGetStageDirectory(): void {
    // Don't mirror the active directory from the virtual project into the
    // real file system.
    Beginner::setFixturePath(NULL);

    /** @var \Drupal\package_manager_bypass\PathLocator $path_locator */
    $path_locator = $this->container->get('package_manager.path_locator');

    $stage = $this->createStage();
    $id = $stage->create();
    $stage_dir = $stage->getStageDirectory();
    $this->assertStringStartsWith($path_locator->getStagingRoot() . '/', $stage_dir);
    $this->assertStringEndsWith("/$id", $stage_dir);
    // If the staging root is changed, the existing stage shouldn't be
    // affected...
    $active_dir = $path_locator->getProjectRoot();
    $path_locator->setPaths($active_dir, "$active_dir/vendor", '', '/junk/drawer');
    $this->assertSame($stage_dir, $stage->getStageDirectory());
    $stage->destroy();
    // ...but a new stage should be.
    $stage = $this->createStage();
    $another_id = $stage->create();
    $this->assertNotSame($id, $another_id);
    $stage_dir = $stage->getStageDirectory();
    $this->assertStringStartsWith('/junk/drawer/', $stage_dir);
    $this->assertStringEndsWith("/$another_id", $stage_dir);
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
   * Data provider for testDestroyDuringApply().
   *
   * @return mixed[][]
   *   The test cases.
   */
  public function providerDestroyDuringApply(): array {
    return [
      'force destroy on pre-apply, fresh' => [
        PreApplyEvent::class,
        TRUE,
        1,
        TRUE,
      ],
      'destroy on pre-apply, fresh' => [
        PreApplyEvent::class,
        FALSE,
        1,
        TRUE,
      ],
      'force destroy on pre-apply, stale' => [
        PreApplyEvent::class,
        TRUE,
        7200,
        FALSE,
      ],
      'destroy on pre-apply, stale' => [
        PreApplyEvent::class,
        FALSE,
        7200,
        FALSE,
      ],
      'force destroy on post-apply, fresh' => [
        PostApplyEvent::class,
        TRUE,
        1,
        TRUE,
      ],
      'destroy on post-apply, fresh' => [
        PostApplyEvent::class,
        FALSE,
        1,
        TRUE,
      ],
      'force destroy on post-apply, stale' => [
        PostApplyEvent::class,
        TRUE,
        7200,
        FALSE,
      ],
      'destroy on post-apply, stale' => [
        PostApplyEvent::class,
        FALSE,
        7200,
        FALSE,
      ],
    ];
  }

  /**
   * Tests destroying a stage while applying it.
   *
   * @param string $event_class
   *   The event class for which to attempt to destroy the stage.
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
  public function testDestroyDuringApply(string $event_class, bool $force, int $time_offset, bool $expect_exception): void {
    $listener = function (StageEvent $event) use ($force, $time_offset): void {
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
      ->addListener($event_class, $listener);

    $stage = $this->createStage();
    $stage->create();
    $stage->require(['ext-json:*']);
    if ($expect_exception) {
      $this->expectException(StageException::class);
      $this->expectExceptionMessage('Cannot destroy the staging area while it is being applied to the active directory.');
    }
    $stage->apply();

    // If the stage was successfully destroyed by the event handler (i.e., the
    // stage has been applying for too long and is therefore considered stale),
    // the postApply() method should fail because the stage is not claimed.
    if ($stage->isAvailable()) {
      $this->expectException('LogicException');
      $this->expectExceptionMessage('Stage must be claimed before performing any operations on it.');
    }
    $stage->postApply();
  }

  /**
   * Test uninstalling any module while the staged changes are being applied.
   */
  public function testUninstallModuleDuringApply(): void {
    $listener = function (PreApplyEvent $event): void {
      $this->assertTrue($event->getStage()->isApplying());

      // Trying to uninstall any module while the stage is being applied should
      // result in a module uninstall validation error.
      try {
        $this->container->get('module_installer')
          ->uninstall(['package_manager_bypass']);
        $this->fail('Expected an exception to be thrown while uninstalling a module.');
      }
      catch (ModuleUninstallValidatorException $e) {
        $this->assertStringContainsString('Modules cannot be uninstalled while Package Manager is applying staged changes to the active code base.', $e->getMessage());
      }
    };
    $this->container->get('event_dispatcher')
      ->addListener(PreApplyEvent::class, $listener);

    $stage = $this->createStage();
    $stage->create();
    $stage->require(['ext-json:*']);
    $stage->apply();
  }

  /**
   * Tests that Composer Stager is invoked with a long timeout.
   */
  public function testTimeouts(): void {
    $stage = $this->createStage();
    $stage->create(420);
    $stage->require(['ext-json:*']);
    $stage->apply();

    $timeouts = [
      // The beginner was given an explicit timeout.
      'package_manager.beginner' => 420,
      // The committer should have been called with a longer timeout than
      // Composer Stager's default of 120 seconds.
      'package_manager.committer' => 600,
    ];
    foreach ($timeouts as $service_id => $expected_timeout) {
      $invocations = $this->container->get($service_id)->getInvocationArguments();

      // The service should have been called with the expected timeout.
      $this->assertCount(1, $invocations);
      $this->assertSame($expected_timeout, end($invocations[0]));
    }
  }

  /**
   * Data provider for testCommitException().
   *
   * @return \string[][]
   *   The test cases.
   */
  public function providerCommitException(): array {
    return [
      'RuntimeException to ApplyFailedException' => [
        'RuntimeException',
        ApplyFailedException::class,
      ],
      'InvalidArgumentException' => [
        InvalidArgumentException::class,
        StageException::class,
      ],
      'PreconditionException' => [
        PreconditionException::class,
        StageException::class,
      ],
      'Exception' => [
        'Exception',
        ApplyFailedException::class,
      ],
    ];
  }

  /**
   * Tests exception handling during calls to Composer Stager commit.
   *
   * @param string $thrown_class
   *   The throwable class that should be thrown by Composer Stager.
   * @param string|null $expected_class
   *   The expected exception class, if different from $thrown_class.
   *
   * @dataProvider providerCommitException
   */
  public function testCommitException(string $thrown_class, string $expected_class = NULL): void {
    $stage = $this->createStage();
    $stage->create();
    $thrown_message = 'A very bad thing happened';
    // PreconditionException requires a preconditions object.
    if ($thrown_class === PreconditionException::class) {
      $throwable = new PreconditionException($this->prophesize(PreconditionInterface::class)->reveal(), $thrown_message, 123);
    }
    else {
      $throwable = new $thrown_class($thrown_message, 123);
    }
    Committer::setException($throwable);
    $this->expectException($expected_class);
    $this->expectExceptionMessage($thrown_message);
    $this->expectExceptionCode(123);
    $stage->require(['drupal/core' => '9.8.1']);
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
