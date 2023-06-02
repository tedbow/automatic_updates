<?php

namespace Drupal\Tests\automatic_updates\Functional;

use Drupal\automatic_updates\CronUpdateStage;

/**
 * Tests that updates are not run by Automated Cron.
 *
 * @covers \Drupal\automatic_updates\Validator\AutomatedCronDisabledValidator
 * @group automatic_updates
 */
class AutomatedCronDisabledValidatorTest extends AutomaticUpdatesFunctionalTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['dblog', 'automated_cron'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalLogin($this->createUser(['access site reports']));
  }

  /**
   * Tests that automatic updates are not triggered by Automated Cron.
   */
  public function testAutomatedCronUpdate() {
    // Delete the last cron run time, to ensure that Automated Cron will run.
    $this->container->get('state')->delete('system.cron_last');
    $this->config('automatic_updates.settings')
      ->set('unattended.level', CronUpdateStage::ALL)
      ->save();

    $this->drupalGet('user');
    // The `drupalGet()` will not wait for the HTTP kernel to terminate (i.e.,
    // the `KernelEvents::TERMINATE` event) to complete. Although this event
    // will likely already be completed, wait 1 second to avoid random test
    // failures.
    sleep(1);
    $this->drupalGet('admin/reports/dblog');
    $this->assertSession()->elementAttributeContains('css', 'a[title^="Unattended"]', 'title', 'Unattended automatic updates were triggered by Automated Cron, which is not supported. No update was performed. See the status report for more information.');
  }

}
