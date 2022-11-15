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
   * Tests that no error is raised if there are no pending updates.
   */
  public function testNoPendingUpdates(): void {
    $this->assertStatusCheckResults([]);
    $this->assertResults([], PreCreateEvent::class);
  }

  /**
   * Tests that an error is raised if there are pending schema updates.
   *
   * @depends testNoPendingUpdates
   */
  public function testPendingUpdateHook(): void {
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
    $this->assertStatusCheckResults([$result]);
    $this->assertResults([$result], PreCreateEvent::class);
  }

  /**
   * Tests that an error is raised if there are pending post-updates.
   */
  public function testPendingPostUpdate(): void {
    $this->registerPostUpdateFunctions();
    // Make an additional post-update function available; the update registry
    // will think it's pending.
    require_once __DIR__ . '/../../fixtures/post_update.php';
    $result = ValidationResult::createError([
      'Some modules have database schema updates to install. You should run the <a href="/update.php">database update script</a> immediately.',
    ]);
    $this->assertStatusCheckResults([$result]);
    $this->assertResults([$result], PreCreateEvent::class);
  }

}
