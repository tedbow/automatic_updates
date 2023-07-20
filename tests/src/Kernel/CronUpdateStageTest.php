<?php

declare(strict_types = 1);

namespace Drupal\Tests\automatic_updates\Kernel;

use Drupal\automatic_updates\CronUpdateStage;
use Drupal\Tests\automatic_updates\Traits\EmailNotificationsTestTrait;
use Drupal\Tests\package_manager\Traits\PackageManagerBypassTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * @coversDefaultClass  \Drupal\automatic_updates\CronUpdateStage
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
   *
   * @covers ::run
   */
  public function testRegularCronRuns(): void {
    // Ensure that if the terminal command is attempted an expection will be
    // raised.
    /** @var \Drupal\Tests\automatic_updates\Kernel\TestCronUpdateStage $cron_stage */
    $cron_stage = $this->container->get(CronUpdateStage::class);
    $cron_stage->throwExceptionOnTerminalCommand = TRUE;
    // Undo override of the 'serverApi' property from the parent test class.
    // @see \Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase::setUp
    $property = new \ReflectionProperty(CronUpdateStage::class, 'serverApi');
    $property->setValue(NULL, 'cli');
    $this->assertTrue(CronUpdateStage::isCommandLine());

    // Since we're at the command line the terminal command should not be
    // invoked.
    $this->container->get('cron')->run();
    // Even though the terminal command was not invoke hook_cron
    // implementations should have been invoked.
    $this->assertCronRan(TRUE);

    // If we are on the web but the method is set to 'console the terminal
    // command should not be invoked.
    $property->setValue(NULL, 'cgi-fcgi');
    $this->assertFalse(CronUpdateStage::isCommandLine());
    $this->config('automatic_updates.settings')
      ->set('unattended.method', 'console')
      ->save();
    $this->container->get('cron')->run();
    $this->assertCronRan(TRUE);

    $this->config('automatic_updates.settings')
      ->set('unattended.method', 'web')
      ->save();
    try {
      $this->container->get('cron')->run();
      $this->fail('Expected cron exception');
    }
    catch (\Exception $e) {
      $this->assertSame('Simulated process failure.', $e->getMessage());
    }
    // Even though the terminal command threw exception hook_cron
    // implementations should have been invoked before this.
    $this->assertCronRan(TRUE);
  }

  /**
   * Asserts whether cron has run.
   *
   * @param bool $expected_cron_run
   *   Whether cron is expected to have run.
   *
   * @see \common_test_cron_helper_cron()
   */
  private function assertCronRan(bool $expected_cron_run): void {
    $this->assertTrue(
      $this->container->get('module_handler')->moduleExists('common_test_cron_helper'),
      '\Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase::assertCronRan can only be used if common_test_cron_helper is enabled.'
    );
    $this->assertSame($expected_cron_run, $this->container->get('state')->get('common_test.cron') === 'success');
    $this->container->get('state')->delete('common_test.cron');
  }

}
