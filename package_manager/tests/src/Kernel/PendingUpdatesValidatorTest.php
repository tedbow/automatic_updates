<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\ValidationResult;

/**
 * @covers \Drupal\package_manager\EventSubscriber\PendingUpdatesValidator
 *
 * @group package_manager
 */
class PendingUpdatesValidatorTest extends PackageManagerKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * Registers all of the System module's post-update functions.
   *
   * Since kernel tests don't normally install modules and register their
   * updates, this method makes sure that the validator is tested from a clean,
   * fully up-to-date state.
   */
  private function registerPostUpdateFunctions(): void {
    $updates = $this->container->get('update.post_update_registry')
      ->getPendingUpdateFunctions();

    $this->container->get('keyvalue')
      ->get('post_update')
      ->set('existing_updates', $updates);
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
