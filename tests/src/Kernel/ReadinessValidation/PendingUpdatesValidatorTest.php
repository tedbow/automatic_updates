<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessValidation;

use Drupal\automatic_updates\Event\UpdateEvent;
use Drupal\automatic_updates\Validation\ValidationResult;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\automatic_updates\Traits\ValidationTestTrait;

/**
 * @covers \Drupal\automatic_updates\Validator\PendingUpdatesValidator
 *
 * @group automatic_updates
 */
class PendingUpdatesValidatorTest extends KernelTestBase {

  use ValidationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'automatic_updates',
    'package_manager',
    'system',
    'update',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Make the update system think that all of System's post-update functions
    // have run. Since kernel tests don't normally install modules and register
    // their updates, we need to do this so that the validator is being tested
    // from a clean, fully up-to-date state.
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
    $event = new UpdateEvent();
    $this->container->get('automatic_updates.pending_updates_validator')
      ->checkPendingUpdates($event);
    $this->assertEmpty($event->getResults());
  }

  /**
   * Tests that an error is raised if there are pending schema updates.
   */
  public function testPendingUpdateHook(): void {
    require __DIR__ . '/../../../fixtures/db_update.php';

    $this->container->get('keyvalue')
      ->get('system.schema')
      ->set('automatic_updates', \Drupal::CORE_MINIMUM_SCHEMA_VERSION);

    $result = ValidationResult::createError(['Some modules have database schema updates to install. You should run the <a href="/update.php">database update script</a> immediately.']);

    $event = new UpdateEvent();
    $this->container->get('automatic_updates.pending_updates_validator')
      ->checkPendingUpdates($event);
    $this->assertValidationResultsEqual([$result], $event->getResults());
  }

  /**
   * Tests that an error is raised if there are pending post-updates.
   */
  public function testPendingPostUpdate(): void {
    require __DIR__ . '/../../../fixtures/post_update.php';

    $result = ValidationResult::createError(['Some modules have database schema updates to install. You should run the <a href="/update.php">database update script</a> immediately.']);

    $event = new UpdateEvent();
    $this->container->get('automatic_updates.pending_updates_validator')
      ->checkPendingUpdates($event);
    $this->assertValidationResultsEqual([$result], $event->getResults());
  }

}
