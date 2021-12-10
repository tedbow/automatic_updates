<?php

namespace Drupal\Tests\automatic_updates\Kernel;

use Drupal\automatic_updates\CronUpdater;
use Drupal\Core\Form\FormState;
use Drupal\update\UpdateSettingsForm;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @covers \Drupal\automatic_updates\CronUpdater
 * @covers \automatic_updates_form_update_settings_alter
 *
 * @group automatic_updates
 */
class CronUpdaterTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'automatic_updates',
    'package_manager',
    'package_manager_bypass',
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
        "$fixture_dir/drupal.9.8.1.xml",
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
        "$fixture_dir/drupal.9.8.1.xml",
        FALSE,
      ],
      'enabled, normal release' => [
        CronUpdater::ALL,
        "$fixture_dir/drupal.9.8.1.xml",
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
    $this->assertCount($will_update, $this->container->get('package_manager.stager')->getInvocationArguments());
    $this->assertCount($will_update, $this->container->get('package_manager.committer')->getInvocationArguments());
  }

}
