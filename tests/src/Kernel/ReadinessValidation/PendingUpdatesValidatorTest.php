<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessValidation;

use Drupal\package_manager\ValidationResult;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;

/**
 * @covers \Drupal\automatic_updates\Validator\PendingUpdatesValidator
 *
 * @group automatic_updates
 */
class PendingUpdatesValidatorTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'automatic_updates',
    'package_manager',
  ];

  /**
   * Tests that no error is raised if there are no pending updates.
   */
  public function testNoPendingUpdates(): void {
    $this->assertCheckerResultsFromManager([], TRUE);
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
    $this->assertCheckerResultsFromManager([$result], TRUE);
  }

  /**
   * Tests that an error is raised if there are pending post-updates.
   */
  public function testPendingPostUpdate(): void {
    require __DIR__ . '/../../../fixtures/post_update.php';
    $result = ValidationResult::createError(['Some modules have database schema updates to install. You should run the <a href="/update.php">database update script</a> immediately.']);
    $this->assertCheckerResultsFromManager([$result], TRUE);
  }

}
