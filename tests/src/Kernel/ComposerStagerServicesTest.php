<?php

namespace Drupal\Tests\automatic_updates\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests that the Composer Stager library's services are available.
 *
 * @todo Remove this test when Composer Stager's API is stable and tagged.
 *
 * @group automatic_updates
 */
class ComposerStagerServicesTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates', 'update'];

  /**
   * Tests that the Composer Stager services are available in the container.
   */
  public function testServices(): void {
    $services = [
      'automatic_updates.beginner',
      'automatic_updates.stager',
      'automatic_updates.cleaner',
      'automatic_updates.committer',
      'automatic_updates.composer_runner',
      'automatic_updates.file_copier',
      'automatic_updates.file_system',
      'automatic_updates.symfony_file_system',
      'automatic_updates.symfony_exec_finder',
      'automatic_updates.rsync',
      'automatic_updates.exec_finder',
      'automatic_updates.process_factory',
    ];
    foreach ($services as $service_id) {
      $service = $this->container->get($service_id);
      $this->assertIsObject($service);
      $this->assertSame($service_id, $service->_serviceId);
    }
  }

}
