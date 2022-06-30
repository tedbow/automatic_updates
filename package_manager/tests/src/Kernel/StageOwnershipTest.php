<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\Core\File\FileSystem;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\package_manager\Event\PostDestroyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Exception\StageException;
use Drupal\package_manager\Exception\StageOwnershipException;
use Drupal\package_manager_bypass\Stager;
use Drupal\package_manager_test_validation\EventSubscriber\TestSubscriber;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Psr\Log\Test\TestLogger;

/**
 * Tests that ownership of the stage is enforced.
 *
 * @group package_manger
 */
class StageOwnershipTest extends PackageManagerKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'package_manager_test_validation',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('system', ['sequences']);
    $this->installSchema('user', ['users_data']);
    $this->installEntitySchema('user');
    $this->registerPostUpdateFunctions();
  }

  /**
   * Tests only the owner of stage can perform operations, even if logged out.
   */
  public function testOwnershipEnforcedWhenLoggedOut(): void {
    $this->assertOwnershipIsEnforced($this->createStage(), $this->createStage());
  }

  /**
   * Tests only the owner of stage can perform operations.
   */
  public function testOwnershipEnforcedWhenLoggedIn(): void {
    $user_1 = $this->createUser([], NULL, FALSE, ['uid' => 2]);
    $this->setCurrentUser($user_1);

    $will_create = $this->createStage();
    // Rebuild the container so that the shared tempstore factory is made
    // properly aware of the new current user ($user_2) before another stage
    // is created.
    $kernel = $this->container->get('kernel');
    $this->container = $kernel->rebuildContainer();
    $user_2 = $this->createUser();
    $this->setCurrentUser($user_2);
    $this->assertOwnershipIsEnforced($will_create, $this->createStage());
  }

  /**
   * Asserts that ownership is enforced across staging areas.
   *
   * @param \Drupal\Tests\package_manager\Kernel\TestStage $will_create
   *   The stage that will be created, and owned by the current user or session.
   * @param \Drupal\Tests\package_manager\Kernel\TestStage $never_create
   *   The stage that will not be created, but should still respect the
   *   ownership and status of the other stage.
   */
  private function assertOwnershipIsEnforced(TestStage $will_create, TestStage $never_create): void {
    // Before the staging area is created, isAvailable() should return TRUE.
    $this->assertTrue($will_create->isAvailable());
    $this->assertTrue($never_create->isAvailable());

    $stage_id = $will_create->create();
    // Both staging areas should be considered unavailable (i.e., cannot be
    // created until the existing one is destroyed first).
    $this->assertFalse($will_create->isAvailable());
    $this->assertFalse($never_create->isAvailable());

    // We should get an error if we try to create the staging area again,
    // regardless of who owns it.
    foreach ([$will_create, $never_create] as $stage) {
      try {
        $stage->create();
        $this->fail("Able to create a stage that already exists.");
      }
      catch (StageException $exception) {
        $this->assertSame('Cannot create a new stage because one already exists.', $exception->getMessage());
      }
    }

    try {
      $never_create->claim($stage_id);
    }
    catch (StageOwnershipException $exception) {
      $this->assertSame('Cannot claim the stage because it is not owned by the current user or session.', $exception->getMessage());
    }

    // Only the stage's owner should be able to move it through its life cycle.
    $callbacks = [
      'require' => [
        ['vendor/lib:0.0.1'],
      ],
      'apply' => [],
      'postApply' => [],
      'destroy' => [],
    ];
    foreach ($callbacks as $method => $arguments) {
      try {
        $never_create->$method(...$arguments);
        $this->fail("Able to call '$method' on a stage that was never created.");
      }
      catch (\LogicException $exception) {
        $this->assertSame('Stage must be claimed before performing any operations on it.', $exception->getMessage());
      }
      // The call should succeed on the created stage.
      $will_create->$method(...$arguments);
    }
  }

  /**
   * Tests behavior of claiming a stage.
   */
  public function testClaim(): void {
    // Log in as a user so that any stage instances created during the session
    // should be able to successfully call ::claim().
    $user_2 = $this->createUser([], NULL, FALSE, ['uid' => 2]);
    $this->setCurrentUser($user_2);
    $creator_stage = $this->createStage();

    // Ensure that exceptions thrown during ::create() will not lock the stage.
    $error = new \Exception('I am going to stop stage creation.');
    TestSubscriber::setException($error, PreCreateEvent::class);
    try {
      $creator_stage->create();
      $this->fail('Was able to create the stage despite throwing an exception in pre-create.');
    }
    catch (\RuntimeException $exception) {
      $this->assertSame($error->getMessage(), $exception->getMessage());
    }

    // The stage should be available, and throw if we try to claim it.
    $this->assertTrue($creator_stage->isAvailable());
    try {
      $creator_stage->claim('any-id-would-fail');
      $this->fail('Was able to claim a stage that has not been created.');
    }
    catch (StageException $exception) {
      $this->assertSame('Cannot claim the stage because no stage has been created.', $exception->getMessage());
    }
    TestSubscriber::setException(NULL, PreCreateEvent::class);

    // Even if we own the stage, we should not be able to claim it with an
    // incorrect ID.
    $stage_id = $creator_stage->create();
    try {
      $this->createStage()->claim('not-correct-id');
      $this->fail('Was able to claim an owned stage with an incorrect ID.');
    }
    catch (StageOwnershipException $exception) {
      $this->assertSame('Cannot claim the stage because the current lock does not match the stored lock.', $exception->getMessage());
    }

    // A stage that is successfully claimed should be able to call any method
    // for its life cycle.
    $callbacks = [
      'require' => [
        ['vendor/lib:0.0.1'],
      ],
      'apply' => [],
      'postApply' => [],
      'destroy' => [],
    ];
    // Since we deliberately don't call create() on the stages we create as
    // we loop through the life cycle methods, ensure that the active directory
    // is mirrored into the staging area when a package is required.
    $active_dir = $this->container->get('package_manager.path_locator')
      ->getProjectRoot();
    Stager::setFixturePath($active_dir);
    foreach ($callbacks as $method => $arguments) {
      // Create a new stage instance for each method.
      $this->createStage()->claim($stage_id)->$method(...$arguments);
    }

    // The stage cannot be claimed after it's been destroyed.
    try {
      $this->createStage()->claim($stage_id);
      $this->fail('Was able to claim an owned stage after it was destroyed.');
    }
    catch (StageException $exception) {
      $this->assertSame('Cannot claim the stage because no stage has been created.', $exception->getMessage());
    }

    // Create a new stage and then log in as a different user.
    $new_stage_id = $this->createStage()->create();
    $user_3 = $this->createUser([], NULL, FALSE, ['uid' => 3]);
    $this->setCurrentUser($user_3);

    // Even if they use the correct stage ID, the current user cannot claim a
    // stage they didn't create.
    try {
      $this->createStage()->claim($new_stage_id);
    }
    catch (StageOwnershipException $exception) {
      $this->assertSame('Cannot claim the stage because it is not owned by the current user or session.', $exception->getMessage());
    }
  }

  /**
   * Tests a stage being destroyed by a user who doesn't own it.
   */
  public function testForceDestroy(): void {
    $owned = $this->createStage();
    $owned->create();

    $not_owned = $this->createStage();
    try {
      $not_owned->destroy();
      $this->fail("Able to destroy a stage that we don't own.");
    }
    catch (\LogicException $exception) {
      $this->assertSame('Stage must be claimed before performing any operations on it.', $exception->getMessage());
    }
    // We should be able to destroy the stage if we ignore ownership.
    $not_owned->destroy(TRUE);
  }

  /**
   * Tests that the stage is available if ::destroy() has a file system error.
   */
  public function testStageDestroyedWithFileSystemError(): void {
    // Enable the Composer Stager library, since we will actually want to create
    // the stage directory.
    $this->container->get('module_installer')->uninstall([
      'package_manager_bypass',
    ]);
    // Ensure we have an up-to-date container.
    $this->container = $this->container->get('kernel')->getContainer();

    $logger_channel = $this->container->get('logger.channel.file');
    $arguments = [
      $this->container->get('stream_wrapper_manager'),
      $this->container->get('settings'),
      $logger_channel,
    ];
    $this->container->set('file_system', new class (...$arguments) extends FileSystem {

      /**
       * {@inheritdoc}
       */
      public function chmod($uri, $mode = NULL) {
        // Normally, the stage will call this method as it tries to make
        // everything in the staging area writable so it can be deleted. We
        // don't wan't to do that in this test, since we're specifically testing
        // what happens when we try to delete a staging area with unwritable
        // files.
      }

    });

    $stage = $this->createStage();
    $this->assertTrue($stage->isAvailable());
    $stage->create();
    $this->assertFalse($stage->isAvailable());

    // Make the stage directory unwritable, which should prevent files in it
    // from being deleted.
    $dir = $stage->getStageDirectory();
    chmod($dir, 0400);
    $this->assertDirectoryIsNotWritable($dir);

    $logger = new TestLogger();
    $logger_channel->addLogger($logger);

    // Listen to the post-destroy event so we can confirm that it was fired, and
    // the stage was made available, despite the file system error.
    $stage_available = NULL;
    $this->container->get('event_dispatcher')
      ->addListener(PostDestroyEvent::class, function (PostDestroyEvent $event) use (&$stage_available): void {
        $stage_available = $event->getStage()->isAvailable();
      });
    $stage->destroy();
    $this->assertTrue($stage_available);

    // A file system error should have been logged while trying to delete the
    // stage directory.
    $predicate = function (array $record) use ($dir): bool {
      return (
        $record['message'] === "Failed to unlink file '%path'." &&
        isset($record['context']['%path']) &&
        str_contains($record['context']['%path'], $dir)
      );
    };
    $this->assertTrue($logger->hasRecordThatPasses($predicate, RfcLogLevel::ERROR));
  }

}
