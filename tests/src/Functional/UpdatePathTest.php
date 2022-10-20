<?php

namespace Drupal\Tests\automatic_updates\Functional;

use Drupal\FunctionalTests\Update\UpdatePathTestBase;

/**
 * @group automatic_updates
 */
class UpdatePathTest extends UpdatePathTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setDatabaseDumpFiles() {
    $this->databaseDumpFiles = [
      $this->getDrupalRoot() . '/core/modules/system/tests/fixtures/update/drupal-9.3.0.filled.standard.php.gz',
      __DIR__ . '/../../fixtures/automatic_updates-installed.php.gz',
    ];
  }

  /**
   * Tests the update path for Automatic Updates.
   */
  public function testUpdatePath(): void {
    /** @var \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface $key_value */
    $key_value = $this->container->get('keyvalue.expirable')
      ->get('automatic_updates');

    $map = [
      'readiness_validation_last_run' => 'status_check_last_run',
      'readiness_check_timestamp' => 'status_check_timestamp',
    ];
    foreach ($map as $old_key => $new_key) {
      $this->assertFalse($key_value->has($new_key));

      $value = $key_value->get($old_key);
      $this->assertNotEmpty($value);
      // Ensure the stored value will still be retrievable.
      $key_value->setWithExpire($old_key, $value, 3600);
    }
    $this->runUpdates();
    foreach ($map as $new_key) {
      $this->assertNotEmpty($key_value->get($new_key));
    }

    // Ensure that the router was rebuilt and routes have the expected changes.
    $routes = $this->container->get('router')->getRouteCollection();
    $routes = array_map([$routes, 'get'], [
      'system.batch_page.html',
      'system.status',
      'system.theme_install',
      'update.confirmation_page',
      'update.module_install',
      'update.module_update',
      'update.report_install',
      'update.report_update',
      'update.settings',
      'update.status',
      'update.theme_update',
      'automatic_updates.status_check',
    ]);
    foreach ($routes as $route) {
      $this->assertNotEmpty($route);
      $this->assertSame('skip', $route->getOption('_automatic_updates_status_messages'));
      $this->assertFalse($route->hasOption('_automatic_updates_readiness_messages'));
    }
  }

}
