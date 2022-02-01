<?php

namespace Drupal\Tests\automatic_updates\Kernel;

use Drupal\automatic_updates\CronUpdater;
use Drupal\automatic_updates_test\ReadinessChecker\TestChecker1;
use Drupal\Core\Form\FormState;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\ValidationResult;
use Drupal\Tests\package_manager\Traits\PackageManagerBypassTestTrait;
use Drupal\update\UpdateSettingsForm;
use Psr\Log\Test\TestLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @covers \Drupal\automatic_updates\CronUpdater
 * @covers \automatic_updates_form_update_settings_alter
 *
 * @group automatic_updates
 */
class CronUpdaterTest extends AutomaticUpdatesKernelTestBase {

  use PackageManagerBypassTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'automatic_updates',
    'package_manager',
    'package_manager_bypass',
    'automatic_updates_test',
  ];

  /**
   * Data provider for ::testUpdaterCalled().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerUpdaterCalled(): array {
    $fixture_dir = __DIR__ . '/../../fixtures/release-history';

    return [
      'disabled, normal release' => [
        CronUpdater::DISABLED,
        "$fixture_dir/drupal.9.8.2.xml",
        FALSE,
      ],
      'disabled, security release' => [
        CronUpdater::DISABLED,
        "$fixture_dir/drupal.9.8.1-security.xml",
        FALSE,
      ],
      'security only, security release' => [
        CronUpdater::SECURITY,
        "$fixture_dir/drupal.9.8.1-security.xml",
        TRUE,
      ],
      'security only, normal release' => [
        CronUpdater::SECURITY,
        "$fixture_dir/drupal.9.8.2.xml",
        FALSE,
      ],
      'enabled, normal release' => [
        CronUpdater::ALL,
        "$fixture_dir/drupal.9.8.2.xml",
        TRUE,
      ],
      'enabled, security release' => [
        CronUpdater::ALL,
        "$fixture_dir/drupal.9.8.1-security.xml",
        TRUE,
      ],
    ];
  }

  /**
   * Tests that the cron handler calls the updater as expected.
   *
   * @param string $setting
   *   Whether automatic updates should be enabled during cron. Possible values
   *   are 'disable', 'security', and 'patch'.
   * @param string $release_data
   *   If automatic updates are enabled, the path of the fake release metadata
   *   that should be served when fetching information on available updates.
   * @param bool $will_update
   *   Whether an update should be performed, given the previous two arguments.
   *
   * @dataProvider providerUpdaterCalled
   */
  public function testUpdaterCalled(string $setting, string $release_data, bool $will_update): void {
    // Our form alter does not refresh information on available updates, so
    // ensure that the appropriate update data is loaded beforehand.
    $this->setReleaseMetadata($release_data);
    $this->setCoreVersion('9.8.0');
    update_get_available(TRUE);

    // Submit the configuration form programmatically, to prove our alterations
    // work as expected.
    $form_builder = $this->container->get('form_builder');
    $form_state = new FormState();
    $form = $form_builder->buildForm(UpdateSettingsForm::class, $form_state);
    // Ensure that the version ranges in the setting's description, which are
    // computed dynamically, look correct.
    $this->assertStringContainsString('Automatic updates are only supported for 9.8.x versions of Drupal core. Drupal 9.8 will receive security updates until 9.10.0 is released.', $form['automatic_updates_cron']['#description']);
    $form_state->setValue('automatic_updates_cron', $setting);
    $form_builder->submitForm(UpdateSettingsForm::class, $form_state);

    // Since we're just trying to ensure that all of Package Manager's services
    // are called as expected, disable validation by replacing the event
    // dispatcher with a dummy version.
    $event_dispatcher = $this->prophesize(EventDispatcherInterface::class);
    $this->container->set('event_dispatcher', $event_dispatcher->reveal());

    // Run cron and ensure that Package Manager's services were called or
    // bypassed depending on configuration.
    $this->container->get('cron')->run();

    $will_update = (int) $will_update;
    $this->assertCount($will_update, $this->container->get('package_manager.beginner')->getInvocationArguments());
    // If updates happen, then there will be two calls to the stager: one to
    // change the constraints in composer.json, and another to actually update
    // the installed dependencies.
    $this->assertCount($will_update * 2, $this->container->get('package_manager.stager')->getInvocationArguments());
    $this->assertCount($will_update, $this->container->get('package_manager.committer')->getInvocationArguments());
  }

  /**
   * Data provider for testErrors().
   *
   * @return array[]
   *   The test cases for testErrors().
   */
  public function providerErrors(): array {
    $messages = [
      'PreCreate Event Error',
      'PreCreate Event Error 2',
    ];
    $summary = 'There were errors in updates';
    $result_no_summary = ValidationResult::createError([$messages[0]]);
    $result_with_summary = ValidationResult::createError($messages, t($summary));
    $result_with_summary_message = "<h3>{$summary}</h3><ul><li>{$messages[0]}</li><li>{$messages[1]}</li></ul>";

    return [
      '1 result with summary' => [
        [$result_with_summary],
        $result_with_summary_message,
      ],
      '2 results with summary' => [
        [$result_with_summary, $result_with_summary],
        "$result_with_summary_message$result_with_summary_message",
      ],
      '1 result without summary' => [
        [$result_no_summary],
        $messages[0],
      ],
      '2 results without summary' => [
        [$result_no_summary, $result_no_summary],
        $messages[0] . ' ' . $messages[0],
      ],
      '1 result with summary, 1 result without summary' => [
        [$result_with_summary, $result_no_summary],
        $result_with_summary_message . ' ' . $messages[0],
      ],
    ];
  }

  /**
   * Tests errors during a cron update attempt.
   *
   * @param \Drupal\package_manager\ValidationResult[] $validation_results
   *   The expected validation results which should be logged.
   * @param string $expected_log_message
   *   The error message should be logged.
   *
   * @dataProvider providerErrors
   */
  public function testErrors(array $validation_results, string $expected_log_message): void {
    TestChecker1::setTestResult($validation_results, PreCreateEvent::class);

    $logger = new TestLogger();
    $this->container->get('logger.factory')
      ->get('automatic_updates')
      ->addLogger($logger);

    $this->container->get('cron')->run();
    $this->assertUpdateStagedTimes(0);
    $this->assertTrue($logger->hasRecord("<h2>Unable to complete the update because of errors.</h2>$expected_log_message", RfcLogLevel::ERROR));
  }

}
