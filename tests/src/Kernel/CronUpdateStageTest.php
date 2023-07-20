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
   * Tests that hook_cron implementations are always invoked.
   *
   * @covers ::run
   */
  public function testHookCronInvoked(): void {
    // Delete the state value set when cron runs to ensure next asserts start
    // from a good state.
    // @see \common_test_cron_helper_cron()
    $this->container->get('state')->delete('common_test.cron');

    // Undo override of the 'serverApi' property from the parent test class.
    // @see \Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase::setUp
    $property = new \ReflectionProperty(CronUpdateStage::class, 'serverApi');
    $property->setValue(NULL, 'cli');
    $this->assertTrue(CronUpdateStage::isCommandLine());

    // Since we're at the command line the terminal command should not be
    // invoked.
    $this->container->get('cron')->run();
    // Even though the terminal command was not invoked hook_cron
    // implementations should have been invoked.
    $this->assertCronRan();

    // If we are on the web but the method is set to 'console' the terminal
    // command should not be invoked.
    $property->setValue(NULL, 'cgi-fcgi');
    $this->assertFalse(CronUpdateStage::isCommandLine());
    $this->config('automatic_updates.settings')
      ->set('unattended.method', 'console')
      ->save();
    $this->container->get('cron')->run();
    $this->assertCronRan();

    // If we are on the web and method settings is 'web' the terminal command
    // should be invoked.
    $this->config('automatic_updates.settings')
      ->set('unattended.method', 'web')
      ->save();
    try {
      $this->container->get('cron')->run();
      $this->fail('Expected process exception');
    }
    catch (\Exception $e) {
      $this->assertSame(TestCronUpdateStage::EXPECTED_TERMINAL_EXCEPTION, $e->getMessage());
    }
    // Even though the terminal command threw exception hook_cron
    // implementations should have been invoked before this.
    $this->assertCronRan();
  }

  /**
   * Asserts hook_cron implementations were invoked.
   *
   * @see \common_test_cron_helper_cron()
   */
  private function assertCronRan(): void {
    $this->assertTrue(
      $this->container->get('module_handler')->moduleExists('common_test_cron_helper'),
      '\Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase::assertCronRan can only be used if common_test_cron_helper is enabled.'
    );
    $this->assertSame('success', $this->container->get('state')->get('common_test.cron'));
    // Delete the value so this function can be called again after the next cron
    // attempt.
    $this->container->get('state')->delete('common_test.cron');
  }

}
