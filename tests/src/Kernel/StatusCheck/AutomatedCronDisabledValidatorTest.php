<?php

declare(strict_types = 1);

namespace Drupal\Tests\automatic_updates\Kernel\StatusCheck;

use Drupal\package_manager\ValidationResult;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;

/**
 * @covers \Drupal\automatic_updates\Validator\AutomatedCronDisabledValidator
 * @group automatic_updates
 * @internal
 */
class AutomatedCronDisabledValidatorTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates', 'automated_cron'];

  /**
   * Tests that cron updates are not allowed if Automated Cron is enabled.
   */
  public function testCronUpdateNotAllowed(): void {
    $expected_results = [
      ValidationResult::createWarning([
        t('This site has the Automated Cron module installed. To use unattended automatic updates, please configure cron manually on your hosting environment. The Automatic Updates module will not do anything if it is triggered by Automated Cron. See the <a href=":url">Automated Cron documentation</a> for information.', [
          ':url' => 'https://www.drupal.org/docs/administering-a-drupal-site/cron-automated-tasks/cron-automated-tasks-overview#s-more-reliable-enable-cron-using-external-trigger',
        ]),
      ]),
    ];
    $this->assertCheckerResultsFromManager($expected_results, TRUE);

    // Even after a cron run, we should have the same results.
    $this->container->get('cron')->run();
    $this->assertCheckerResultsFromManager($expected_results);
  }

}
