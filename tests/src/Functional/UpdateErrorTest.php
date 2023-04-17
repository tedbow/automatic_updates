<?php

declare(strict_types = 1);

namespace Drupal\Tests\automatic_updates\Functional;

use Drupal\package_manager\Event\PostApplyEvent;
use Drupal\package_manager\Event\PostCreateEvent;
use Drupal\package_manager\Event\PostDestroyEvent;
use Drupal\package_manager\Event\PostRequireEvent;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreDestroyEvent;
use Drupal\package_manager\Event\PreRequireEvent;
use Drupal\package_manager\Event\StatusCheckEvent;
use Drupal\package_manager\ValidationResult;
use Drupal\automatic_updates_test\EventSubscriber\TestSubscriber1;
use Drupal\package_manager_test_validation\EventSubscriber\TestSubscriber;
use Drupal\system\SystemManager;

/**
 * @covers \Drupal\automatic_updates\Form\UpdaterForm
 * @group automatic_updates
 * @internal
 *
 * @todo Consolidate and remove duplicate test coverage in
 *   https://drupal.org/i/3354325.
 */
class UpdateErrorTest extends UpdaterFormTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->config('system.logging')
      ->set('error_level', ERROR_REPORTING_DISPLAY_VERBOSE)
      ->save();
  }

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
    $this->drupalGet('/admin/modules/update');
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
    $this->assertStatusMessageContainsResult($error);
    $assert_session->buttonNotExists('Continue');
    $assert_session->buttonExists('Cancel update');

    // An error with only one message should also show the summary.
    $error = ValidationResult::createError([t('Yet another smarmy error.')], $summary);
    TestSubscriber::setTestResult([$error], StatusCheckEvent::class);
    $this->getSession()->reload();
    $this->assertStatusMessageContainsResult($error);
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
    $this->assertStatusMessageContainsResult($expected_results[0]);
    $assert_session->pageTextContainsOnce(static::$errorsExplanation);
    $assert_session->pageTextNotContains(static::$warningsExplanation);
    $assert_session->pageTextNotContains($cached_message);
    TestSubscriber1::setTestResult(NULL, StatusCheckEvent::class);

    // Set up an error with one message and a summary. We should see both when
    // we refresh the form.
    $expected_result = $this->createValidationResult(SystemManager::REQUIREMENT_ERROR, 1);
    TestSubscriber1::setTestResult([$expected_result], StatusCheckEvent::class);
    $this->getSession()->reload();
    $this->assertNoUpdateButtons();
    $this->assertStatusMessageContainsResult($expected_result);
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
    $assert_session->pageTextNotContains((string) $expected_results[0]->messages[0]);
    $assert_session->pageTextNotContains($expected_results[0]->summary);
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
    $this->assertStatusMessageContainsResult($expected_results[0]);
    $assert_session->pageTextNotContains($cached_message);
  }

  /**
   * Tests handling of exceptions thrown by event subscribers.
   *
   * @param string $event
   *   The event that should throw an exception.
   *
   * @dataProvider providerExceptionFromEventSubscriber
   */
  public function testExceptionFromEventSubscriber(string $event): void {
    $exception = new \Exception('Bad news bears!');
    TestSubscriber::setException($exception, $event);

    // Only simulate a staged update if we're going to get far enough that the
    // stage directory will be created.
    if ($event !== StatusCheckEvent::class && $event !== PreCreateEvent::class) {
      $this->getStageFixtureManipulator()->setCorePackageVersion('9.8.1');
    }

    $session = $this->getSession();
    $page = $session->getPage();
    $assert_session = $this->assertSession();

    $this->mockActiveCoreVersion('9.8.0');
    $this->checkForUpdates();
    $this->drupalGet('/admin/modules/update');

    // StatusCheckEvent runs very early, before we can even start the update.
    // If it raises the error we're expecting, we're done.
    if ($event === StatusCheckEvent::class) {
      $assert_session->statusMessageContains($exception->getMessage(), 'error');
      // We shouldn't be able to start the update.
      $assert_session->buttonNotExists('Update to 9.8.1');
      return;
    }

    // Start the update.
    $page->pressButton('Update to 9.8.1');
    $this->checkForMetaRefresh();
    // If the batch job fails, proceed to the error page. If it failed because
    // of the exception we set up, we're done.
    if ($page->hasLink('the error page')) {
      // We should see the exception's backtrace.
      $assert_session->responseContains('<pre class="backtrace">');
      $page->clickLink('the error page');
      $assert_session->statusMessageContains($exception->getMessage(), 'error');
      // We should be on the start page.
      $assert_session->addressEquals('/admin/modules/update');

      // If we failed during post-create, the stage is not destroyed, so we
      // should not be able to start the update anew without destroying the
      // stage first. In all other cases, the stage should have been destroyed
      // and we should be able to try again.
      // @todo Delete the existing update on behalf of the user in
      //   https://drupal.org/i/3346644.
      if ($event === PostCreateEvent::class) {
        $assert_session->buttonNotExists('Update to 9.8.1');
        $assert_session->buttonExists('Delete existing update');
      }
      else {
        $assert_session->buttonExists('Update to 9.8.1');
        $assert_session->buttonNotExists('Delete existing update');
      }
      return;
    }

    // We should now be ready to finish the update...
    $this->assertStringContainsString('/admin/automatic-update-ready/', $session->getCurrentUrl());
    // ...but if we set it up to fail on PostRequireEvent, and we see the error
    // message from that, we're done.
    // @todo In https://drupal.org/i/3346644, ensure that PostRequireEvent
    //   behaves the same way as PreCreateEvent, PostCreateEvent, and
    //   PreRequireEvent.
    if ($event === PostRequireEvent::class) {
      $assert_session->statusMessageContains($exception->getMessage(), 'error');
      $assert_session->buttonNotExists('Continue');
      return;
    }

    // Ensure that we are expecting a failure from an event that is dispatched
    // during the second phase (apply and destroy) of the update.
    $this->assertContains($event, [
      PreApplyEvent::class,
      PostApplyEvent::class,
      PreDestroyEvent::class,
      PostDestroyEvent::class,
    ]);
    // Try to finish the update.
    $page->pressButton('Continue');
    $this->checkForMetaRefresh();
    // As we did before, proceed to the error page if the batch job fails. If it
    // failed because of the exception we set up, we're done here.
    if ($page->hasLink('the error page')) {
      // We should see the exception's backtrace.
      $assert_session->responseContains('<pre class="backtrace">');
      $page->clickLink('the error page');
      $assert_session->statusMessageContains($exception->getMessage(), 'error');
      // We should be back on the "ready to update" page.
      $this->assertStringContainsString('/admin/automatic-update-ready/', $session->getCurrentUrl());
      return;
    }
    $this->fail('Expected to encounter an error message during the update process.');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Ensure that any pre- or post-destroy exception we set up during testing
    // does not interfere with the parent class' ability to destroy the stage.
    TestSubscriber::setException(NULL, PreDestroyEvent::class);
    TestSubscriber::setException(NULL, PostDestroyEvent::class);

    parent::tearDown();
  }

  /**
   * Data provider for ::testExceptionFromEventSubscriber().
   *
   * @return array[]
   *   The test cases.
   */
  public function providerExceptionFromEventSubscriber(): array {
    $events = [
      StatusCheckEvent::class,
      PreCreateEvent::class,
      PostCreateEvent::class,
      PreRequireEvent::class,
      PostRequireEvent::class,
      PreApplyEvent::class,
      PostApplyEvent::class,
      PreDestroyEvent::class,
      // @todo PostDestroyEvent leads to an exception with "This operation was
      //   already canceled". This is because the batch processor redirects to
      //   the UpdateReady form, which tries to claim the stage...which has been
      //   destroyed. Fix this in https://drupal.org/i/3354003.
      // PostDestroyEvent::class,
    ];
    return array_combine($events, array_map(fn ($event) => [$event], $events));
  }

}
