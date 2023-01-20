<?php

declare(strict_types = 1);

namespace Drupal\Tests\automatic_updates\Functional;

use Drupal\package_manager\Event\StatusCheckEvent;
use Drupal\package_manager\ValidationResult;
use Drupal\package_manager_test_validation\EventSubscriber\TestSubscriber;

/**
 * @covers \Drupal\automatic_updates\Form\UpdaterForm
 * @group automatic_updates
 * @internal
 */
class UpdateWarningTest extends UpdaterFormTestBase {

  /**
   * Tests that update can be completed even if a status check throws a warning.
   */
  public function testContinueOnWarning(): void {
    $session = $this->getSession();

    $this->mockActiveCoreVersion('9.8.0');
    $this->checkForUpdates();
    $this->drupalGet('/admin/modules/automatic-update');
    $session->getPage()->pressButton('Update to 9.8.1');
    $this->checkForMetaRefresh();
    $this->assertUpdateStagedTimes(1);

    $messages = [
      t("The only thing we're allowed to do is to"),
      t("believe that we won't regret the choice"),
      t("we made."),
    ];
    $summary = t('some generic summary');
    $warning = ValidationResult::createWarning($messages, $summary);
    TestSubscriber::setTestResult([$warning], StatusCheckEvent::class);
    $session->reload();

    $assert_session = $this->assertSession();
    $assert_session->buttonExists('Continue');
    $assert_session->pageTextContains($summary);
    foreach ($messages as $message) {
      $assert_session->pageTextContains($message);
    }
  }

}
