<?php

declare(strict_types = 1);

namespace Drupal\Tests\automatic_updates\Kernel;

use Drupal\automatic_updates\CronUpdateStage;
use Drupal\Tests\automatic_updates\Traits\EmailNotificationsTestTrait;
use Drupal\Tests\package_manager\Traits\PackageManagerBypassTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * @covers \Drupal\automatic_updates\CronUpdateStage
 * @group automatic_updates
 * @internal
 */
class CronUpdateStageTest extends AutomaticUpdatesKernelTestBase {

  use EmailNotificationsTestTrait;
  use PackageManagerBypassTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'automatic_updates',
    'automatic_updates_test',
    'user',
    'common_test_cron_helper',
  ];

  /**
   * Tests that regular cron always runs.
   */
  public function testRegularCronRuns(): void {
    /** @var \Drupal\Tests\automatic_updates\Kernel\TestCronUpdateStage $cron_stage */
    $cron_stage = $this->container->get(CronUpdateStage::class);
    $cron_stage->throwExceptionOnTerminalCommand = TRUE;
    $this->assertRegularCronRun(FALSE);

    try {
      $this->container->get('cron')->run();
      $this->fail('Expected cron exception');
    }

    catch (\Exception $e) {
      $this->assertSame('Simulated process failure.', $e->getMessage());
    }
    $this->assertRegularCronRun(TRUE);
  }

  private function assertRegularCronRun(bool $expected_cron_run) {
    $this->assertSame($expected_cron_run, $this->container->get('state')->get('common_test.cron') === 'success');
  }

}
