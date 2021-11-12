<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\package_manager\StageException;
use Drupal\Tests\user\Traits\UserCreationTrait;

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
  protected static $modules = ['system', 'user'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('system', ['sequences']);
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
    // Before the staging area is created, isOwnedByCurrentUser() should return
    // FALSE and isAvailable() should return TRUE.
    $this->assertFalse($will_create->isOwnedByCurrentUser());
    $this->assertFalse($never_create->isOwnedByCurrentUser());
    $this->assertTrue($will_create->isAvailable());
    $this->assertTrue($never_create->isAvailable());

    $will_create->create();
    // Only the staging area that was actually created should be owned by the
    // current user...
    $this->assertTrue($will_create->isOwnedByCurrentUser());
    $this->assertFalse($never_create->isOwnedByCurrentUser());
    // ...but both staging areas should be considered unavailable (i.e., cannot
    // be created until the existing one is destroyed first).
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

    // Only the stage's owner should be able to move it through its life cycle.
    $callbacks = [
      'require' => [
        ['vendor/lib:0.0.1'],
      ],
      'apply' => [],
      'destroy' => [],
    ];
    foreach ($callbacks as $method => $arguments) {
      try {
        $never_create->$method(...$arguments);
        $this->fail("Able to call '$method' on a stage that was never created.");
      }
      catch (StageException $exception) {
        $this->assertSame('Stage is not owned by the current user or session.', $exception->getMessage());
      }
      // The call should succeed on the created stage.
      $will_create->$method(...$arguments);
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
    catch (StageException $exception) {
      $this->assertSame('Stage is not owned by the current user or session.', $exception->getMessage());
    }
    // We should be able to destroy the stage if we ignore ownership.
    $not_owned->destroy(TRUE);
  }

}
