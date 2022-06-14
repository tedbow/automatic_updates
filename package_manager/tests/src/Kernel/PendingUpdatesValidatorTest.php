<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\ValidationResult;

/**
 * @covers \Drupal\package_manager\Validator\PendingUpdatesValidator
 *
 * @group package_manager
 */
class PendingUpdatesValidatorTest extends PackageManagerKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Use a virtual project so that the test isn't affected by symlinks or
    // other unexpected things that might be present in the running code base.
    $this->createTestProject();
  }

  /**
   * Tests that no error is raised if there are no pending updates.
   */
  public function testNoPendingUpdates(): void {
    $this->registerPostUpdateFunctions();
    $this->assertResults([], PreCreateEvent::class);
  }

  /**
   * Tests that an error is raised if there are pending schema updates.
   *
   * @depends testNoPendingUpdates
   */
  public function testPendingUpdateHook(): void {
    // Register the System module's post-update functions, so that any detected
    // pending updates are guaranteed to be schema updates.
    $this->registerPostUpdateFunctions();

    // Set the installed schema version of Package Manager to its default value
    // and import an empty update hook which is numbered much higher than will
    // ever exist in the real world.
    $this->container->get('keyvalue')
      ->get('system.schema')
      ->set('package_manager', \Drupal::CORE_MINIMUM_SCHEMA_VERSION);

    require_once __DIR__ . '/../../fixtures/db_update.php';

    $result = ValidationResult::createError([
      'Some modules have database schema updates to install. You should run the <a href="/update.php">database update script</a> immediately.',
    ]);
    $this->assertResults([$result], PreCreateEvent::class);
  }

  /**
   * Tests that an error is raised if there are pending post-updates.
   */
  public function testPendingPostUpdate(): void {
    // The System module's post-update functions have not been registered, so
    // the update registry will think they're pending.
    $result = ValidationResult::createError([
      'Some modules have database schema updates to install. You should run the <a href="/update.php">database update script</a> immediately.',
    ]);
    $this->assertResults([$result], PreCreateEvent::class);
  }

}
