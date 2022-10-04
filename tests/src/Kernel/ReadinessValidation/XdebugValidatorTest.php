<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessValidation;

use Drupal\automatic_updates\CronUpdater;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\package_manager\ValidationResult;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;
use Drupal\Tests\package_manager\Traits\PackageManagerBypassTestTrait;
use Psr\Log\Test\TestLogger;

/**
 * @covers \Drupal\automatic_updates\Validator\XdebugValidator
 *
 * @group automatic_updates
 */
class XdebugValidatorTest extends AutomaticUpdatesKernelTestBase {

  use PackageManagerBypassTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // Ensure the validator will think Xdebug is enabled.
    if (!function_exists('xdebug_break')) {
      // @codingStandardsIgnoreLine
      eval('function xdebug_break() {}');
    }
    parent::setUp();

    // The parent class unconditionally disables the Xdebug validator we're
    // testing, so undo that here.
    $validator = $this->container->get('automatic_updates.validator.xdebug');
    $this->container->get('event_dispatcher')->addSubscriber($validator);
  }

  /**
   * Tests warnings and/or errors if Xdebug is enabled.
   */
  public function testXdebugValidation(): void {
    $message = 'Xdebug is enabled, which may have a negative performance impact on Package Manager and any modules that use it.';

    $config = $this->config('automatic_updates.settings');
    // If cron updates are disabled, the readiness check message should only be
    // a warning.
    $config->set('cron', CronUpdater::DISABLED)->save();
    $result = ValidationResult::createWarning([$message]);
    $this->assertCheckerResultsFromManager([$result], TRUE);

    // The parent class' setUp() method simulates an available security update,
    // so ensure that the cron updater will try to update to it.
    $config->set('cron', CronUpdater::SECURITY)->save();

    // If cron updates are enabled the readiness check message should be an
    // error.
    $result = ValidationResult::createError([$message]);
    $this->assertCheckerResultsFromManager([$result], TRUE);

    // Trying to do the update during cron should fail with an error.
    $logger = new TestLogger();
    $this->container->get('logger.factory')
      ->get('automatic_updates')
      ->addLogger($logger);

    $this->container->get('cron')->run();
    $this->assertUpdateStagedTimes(0);
    $this->assertTrue($logger->hasRecordThatMatches("/$message/", RfcLogLevel::ERROR));
  }

}
