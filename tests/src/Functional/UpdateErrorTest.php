<?php

declare(strict_types = 1);

namespace Drupal\Tests\automatic_updates\Functional;

use Drupal\package_manager\Event\PostRequireEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\StatusCheckEvent;
use Drupal\package_manager\ValidationResult;
use Drupal\automatic_updates_test\EventSubscriber\TestSubscriber1;
use Drupal\package_manager_test_validation\EventSubscriber\TestSubscriber;
use Drupal\system\SystemManager;

/**
 * @covers \Drupal\automatic_updates\Form\UpdaterForm
 * @group automatic_updates
 * @internal
 */
class UpdateErrorTest extends UpdaterFormTestBase {

  /**
   * Tests that the update stage is destroyed if an error occurs during require.
   */
  public function testStageDestroyedOnError(): void {
    $session = $this->getSession();
    $assert_session = $this->assertSession();
    $page = $session->getPage();
    $this->mockActiveCoreVersion('9.8.0');
    $this->checkForUpdates();

    $this->drupalGet('/admin/modules/update');
    $error = new \Exception('Some Exception');
    TestSubscriber1::setException($error, PostRequireEvent::class);
    $assert_session->pageTextNotContains(static::$errorsExplanation);
    $assert_session->pageTextNotContains(static::$warningsExplanation);
    $page->pressButton('Update to 9.8.1');
    $this->checkForMetaRefresh();
    $this->assertUpdateStagedTimes(1);
    $assert_session->pageTextContainsOnce('An error has occurred.');
    $page->clickLink('the error page');
    $assert_session->addressEquals('/admin/modules/update');
    $assert_session->pageTextNotContains('Cannot begin an update because another Composer operation is currently in progress.');
    $assert_session->buttonNotExists('Delete existing update');
    $assert_session->pageTextContains('Some Exception');
    $assert_session->buttonExists('Update');
  }

  /**
   * Tests that update cannot be completed via the UI if a status check fails.
   */
  public function testNoContinueOnError(): void {
    $session = $this->getSession();
    $assert_session = $this->assertSession();
    $page = $session->getPage();
    $this->mockActiveCoreVersion('9.8.0');
    $this->checkForUpdates();
    $this->drupalGet('/admin/modules/automatic-update');
    $page->pressButton('Update to 9.8.1');
    $this->checkForMetaRefresh();
    $this->assertUpdateStagedTimes(1);

    $error_messages = [
      t("The only thing we're allowed to do is to"),
      t("believe that we won't regret the choice"),
      t("we made."),
    ];
    $summary = t('some generic summary');
    $error = ValidationResult::createError($error_messages, $summary);
    TestSubscriber::setTestResult([$error], StatusCheckEvent::class);
    $this->getSession()->reload();
    $assert_session->pageTextContains($summary);
    foreach ($error_messages as $message) {
      $assert_session->pageTextContains($message);
    }
    $assert_session->buttonNotExists('Continue');
    $assert_session->buttonExists('Cancel update');
  }

  /**
   * Tests handling of errors and warnings during the update process.
   */
  public function testUpdateErrors(): void {
    $session = $this->getSession();
    $assert_session = $this->assertSession();
    $page = $session->getPage();

    $cached_message = $this->setAndAssertCachedMessage();
    // Ensure that the fake error is cached.
    $session->reload();
    $assert_session->pageTextContainsOnce($cached_message);

    $this->mockActiveCoreVersion('9.8.0');
    $this->checkForUpdates();

    // Set up a new fake error. Use an error with multiple messages so we can
    // ensure that they're all displayed, along with their summary.
    $expected_results = [$this->createValidationResult(SystemManager::REQUIREMENT_ERROR, 2)];
    TestSubscriber1::setTestResult($expected_results, StatusCheckEvent::class);

    // If a validator raises an error during status checking, the form should
    // not have a submit button.
    $this->drupalGet('/admin/modules/update');
    $this->assertNoUpdateButtons();
    // Since this is an administrative page, the error message should be visible
    // thanks to automatic_updates_page_top(). The status checks were re-run
    // during the form build, which means the new error should be cached and
    // displayed instead of the previously cached error.
    $assert_session->pageTextContainsOnce((string) $expected_results[0]->getMessages()[0]);
    $assert_session->pageTextContainsOnce((string) $expected_results[0]->getMessages()[1]);
    $assert_session->pageTextContainsOnce((string) $expected_results[0]->getSummary());
    $assert_session->pageTextContainsOnce(static::$errorsExplanation);
    $assert_session->pageTextNotContains(static::$warningsExplanation);
    $assert_session->pageTextNotContains($cached_message);
    TestSubscriber1::setTestResult(NULL, StatusCheckEvent::class);

    // Make the validator throw an exception during pre-create.
    $error = new \Exception('The update exploded.');
    TestSubscriber1::setException($error, PreCreateEvent::class);
    $session->reload();
    $assert_session->pageTextNotContains(static::$errorsExplanation);
    $assert_session->pageTextNotContains(static::$warningsExplanation);
    $assert_session->pageTextNotContains($cached_message);
    $page->pressButton('Update to 9.8.1');
    $this->checkForMetaRefresh();
    $this->assertUpdateStagedTimes(0);
    $assert_session->pageTextContainsOnce('An error has occurred.');
    $page->clickLink('the error page');
    // We should see the exception message, but not the validation result's
    // messages or summary, because exceptions thrown directly by event
    // subscribers are wrapped in simple exceptions and re-thrown.
    $assert_session->pageTextContainsOnce($error->getMessage());
    $assert_session->pageTextNotContains((string) $expected_results[0]->getMessages()[0]);
    $assert_session->pageTextNotContains($expected_results[0]->getSummary());
    $assert_session->pageTextNotContains($cached_message);
    // Since the error occurred during pre-create, there should be no existing
    // update to delete.
    $assert_session->buttonNotExists('Delete existing update');

    // If a validator flags an error, but doesn't throw, the update should still
    // be halted.
    TestSubscriber1::setTestResult($expected_results, PreCreateEvent::class);
    $page->pressButton('Update to 9.8.1');
    $this->checkForMetaRefresh();
    $this->assertUpdateStagedTimes(0);
    $assert_session->pageTextContainsOnce('An error has occurred.');
    $page->clickLink('the error page');
    $assert_session->pageTextContainsOnce($expected_results[0]->getSummary());
    $assert_session->pageTextContainsOnce((string) $expected_results[0]->getMessages()[0]);
    $assert_session->pageTextContainsOnce((string) $expected_results[0]->getMessages()[1]);
    $assert_session->pageTextNotContains($cached_message);
  }

}
