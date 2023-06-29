<?php

declare(strict_types = 1);

namespace Drupal\Tests\automatic_updates\Kernel\StatusCheck;

use Drupal\automatic_updates\CronUpdateRunner;
use Drupal\package_manager\ValidationResult;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;

/**
 * @covers \Drupal\automatic_updates\Validator\CronFrequencyValidator
 * @group automatic_updates
 * @internal
 */
class CronFrequencyValidatorTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // In this test, we do not want to do an update. We're just testing that
    // cron is configured to run frequently enough to do automatic updates. So,
    // pretend we're already on the latest secure version of core.
    $this->setCoreVersion('9.8.1');
    $this->setReleaseMetadata([
      'drupal' => __DIR__ . '/../../../../package_manager/tests/fixtures/release-history/drupal.9.8.1-security.xml',
    ]);
  }

  /**
   * Tests that nothing is validated if updates are disabled during cron.
   */
  public function testNoValidationIfCronDisabled(): void {
    $this->config('automatic_updates.settings')
      ->set('unattended.level', CronUpdateRunner::DISABLED)
      ->save();
    $state = $this->container->get('state');
    $state->delete('system.cron_last');
    $state->delete('install_time');
    $this->assertCheckerResultsFromManager([], TRUE);
    $this->config('automatic_updates.settings')
      ->set('unattended.level', CronUpdateRunner::ALL)
      ->save();
    $error = ValidationResult::createError([
      t('Cron has not run recently. For more information, see the online handbook entry for <a href="https://www.drupal.org/cron">configuring cron jobs</a> to run at least every 3 hours.'),
    ]);
    $this->assertCheckerResultsFromManager([$error], TRUE);
  }

  /**
   * Data provider for testLastCronRunValidation().
   *
   * @return mixed[][]
   *   The test cases.
   */
  public function providerLastCronRunValidation(): array {
    $error = ValidationResult::createError([
      t('Cron has not run recently. For more information, see the online handbook entry for <a href="https://www.drupal.org/cron">configuring cron jobs</a> to run at least every 3 hours.'),
    ]);

    return [
      'cron never ran' => [
        0,
        [$error],
      ],
      'cron ran four hours ago' => [
        time() - 14400,
        [$error],
      ],
      'cron ran an hour ago' => [
        time() - 3600,
        [],
      ],
    ];
  }

  /**
   * Tests validation based on the last cron run time.
   *
   * @param int $last_run
   *   A timestamp of the last time cron ran.
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results.
   *
   * @dataProvider providerLastCronRunValidation
   */
  public function testLastCronRunValidation(int $last_run, array $expected_results): void {
    $this->container->get('state')->set('system.cron_last', $last_run);
    $this->assertCheckerResultsFromManager($expected_results, TRUE);

    // After running cron, any errors or warnings should be gone.
    $this->container->get('cron')->run();
    $this->assertCheckerResultsFromManager([], TRUE);
  }

}
