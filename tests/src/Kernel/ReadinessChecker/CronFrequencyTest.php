<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessChecker;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests what happens when cron has frequency of greater than 3 hours.
 *
 * @group automatic_updates
 */
class CronFrequencyTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'automated_cron',
    'automatic_updates',
    'system',
  ];

  /**
   * Tests the functionality of supported PHP version readiness checks.
   */
  public function testSupportedPhpVersion() {
    // Module automated_cron is disabled.
    $messages = $this->container->get('automatic_updates.cron_frequency')->run();
    $this->assertEmpty($messages);

    // Module automated_cron has default configuration.
    $this->enableModules(['automated_cron']);
    $messages = $this->container->get('automatic_updates.cron_frequency')->run();
    $this->assertEmpty($messages);

    // Module automated_cron has 6 hour configuration.
    $this->container->get('config.factory')
      ->getEditable('automated_cron.settings')
      ->set('interval', 21600)
      ->save();
    $messages = $this->container->get('automatic_updates.cron_frequency')->run();
    self::assertEquals('Cron is not set to run frequently enough. <a href="/admin/config/system/cron">Configure it</a> to run at least every 3 hours or disable automated cron and run it via an external scheduling system.', $messages[0]);
  }

}
