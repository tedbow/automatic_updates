<?php

namespace Drupal\Tests\automatic_updates\Kernel;

use Drupal\automatic_updates\Validation\StatusChecker;

/**
 * @group automatic_updates
 */
class HookCronTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates'];

  /**
   * Tests that our cron hook does not run if we're at the command line.
   */
  public function testCronHookBypassedAtCommandLine(): void {
    if (PHP_SAPI !== 'cli') {
      $this->markTestSkipped('This test requires that PHP be running at the command line.');
    }

    // The status check should not have run yet.
    /** @var \Drupal\automatic_updates\Validation\StatusChecker $status_checker */
    $status_checker = $this->container->get(StatusChecker::class);
    $this->assertNull($status_checker->getLastRunTime());

    // Since we're at the command line, status checks should still not run, even
    // if we do run cron.
    $this->container->get('cron')->run();
    $this->assertNull($status_checker->getResults());
  }

}
