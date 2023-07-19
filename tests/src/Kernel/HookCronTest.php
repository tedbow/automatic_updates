<?php

namespace Drupal\Tests\automatic_updates\Kernel;

use Drupal\automatic_updates\CronUpdateStage;
use Drupal\automatic_updates_test\Datetime\TestTime;
use Drupal\package_manager\Event\StatusCheckEvent;

/**
 * @group automatic_updates
 */
class HookCronTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates', 'automatic_updates_test'];

  /**
   * Tests that our cron hook will run status checks.
   */
  public function testStatusChecksRunOnCron(): void {
    // Set the core version to 9.8.1 so there will not be an update attempted.
    // The hook_cron implementations will not be run if there is an update.
    // @see \Drupal\automatic_updates\CronUpdateStage::run()
    // @todo Remove this is https://drupal.org/i/3357969
    $this->setCoreVersion('9.8.1');
    // Undo override of the 'serverApi' property from the parent test class.
    // @see \Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase::setUp
    $property = new \ReflectionProperty(CronUpdateStage::class, 'serverApi');
    $property->setValue(NULL, 'cli');
    $this->assertTrue(CronUpdateStage::isCommandLine());
    $status_check_count = 0;
    $this->addEventTestListener(function () use (&$status_check_count) {
      $status_check_count++;
    }, StatusCheckEvent::class);

    // Since we're at the command line, status checks should still not run, even
    // if we do run cron.
    $this->container->get('cron')->run();
    $this->assertSame(0, $status_check_count);

    // If we are on the web the status checks should run.
    $property->setValue(NULL, 'cgi-fcgi');
    $this->assertFalse(CronUpdateStage::isCommandLine());
    $this->container->get('cron')->run();
    $this->assertSame(1, $status_check_count);

    // Ensure that the status checks won't run if less than an hour has passed.
    TestTime::setFakeTimeByOffset("+30 minutes");
    $this->container->get('cron')->run();
    $this->assertSame(1, $status_check_count);

    // The status checks should run if more than an hour has passed.
    TestTime::setFakeTimeByOffset("+61 minutes");
    $this->container->get('cron')->run();
    $this->assertSame(2, $status_check_count);
  }

}
