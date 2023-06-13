<?php

declare(strict_types = 1);

namespace Drupal\Tests\automatic_updates\Kernel;

use Drupal\automatic_updates\CronUpdateStage;
use Drupal\automatic_updates\DrushUpdateStage;
use Drupal\automatic_updates_test\EventSubscriber\TestSubscriber1;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Url;
use Drupal\package_manager\Event\PostApplyEvent;
use Drupal\package_manager\Event\PostCreateEvent;
use Drupal\package_manager\Event\PostDestroyEvent;
use Drupal\package_manager\Event\PostRequireEvent;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreDestroyEvent;
use Drupal\package_manager\Event\PreRequireEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Exception\StageEventException;
use Drupal\package_manager\Exception\StageOwnershipException;
use Drupal\package_manager\ValidationResult;
use Drupal\package_manager_bypass\LoggingCommitter;
use Drupal\Tests\automatic_updates\Traits\EmailNotificationsTestTrait;
use Drupal\Tests\package_manager\Kernel\TestStage;
use Drupal\Tests\package_manager\Traits\PackageManagerBypassTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\update\ProjectRelease;
use Drush\TestTraits\DrushTestTrait;
use Prophecy\Argument;
use ColinODell\PsrTestLogger\TestLogger;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @covers \Drupal\automatic_updates\CronUpdateStage
 * @covers \automatic_updates_test_cron_form_update_settings_alter
 * @group automatic_updates
 * @internal
 */
class CronUpdateStageTest extends AutomaticUpdatesKernelTestBase {

  use DrushTestTrait;
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
   * The test logger.
   *
   * @var \ColinODell\PsrTestLogger\TestLogger
   */
  private $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->logger = new TestLogger();
    $this->container->get('logger.factory')
      ->get('automatic_updates')
      ->addLogger($this->logger);
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);

    $this->setUpEmailRecipients();
    $this->assertRegularCronRun(FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);

    // Since this test dynamically adds additional loggers to certain channels,
    // we need to ensure they will persist even if the container is rebuilt when
    // staged changes are applied.
    // @see ::testStageDestroyedOnError()
    $container->getDefinition('logger.factory')->addTag('persist');
  }

  /**
   * Data provider for testUpdateStageCalled().
   *
   * @return mixed[][]
   *   The test cases.
   */
  public function providerUpdateStageCalled(): array {
    $fixture_dir = __DIR__ . '/../../../package_manager/tests/fixtures/release-history';

    return [
      'disabled, normal release' => [
        CronUpdateStage::DISABLED,
        ['drupal' => "$fixture_dir/drupal.9.8.2.xml"],
        FALSE,
      ],
      'disabled, security release' => [
        CronUpdateStage::DISABLED,
        ['drupal' => "$fixture_dir/drupal.9.8.1-security.xml"],
        FALSE,
      ],
      'security only, security release' => [
        CronUpdateStage::SECURITY,
        ['drupal' => "$fixture_dir/drupal.9.8.1-security.xml"],
        TRUE,
      ],
      'security only, normal release' => [
        CronUpdateStage::SECURITY,
        ['drupal' => "$fixture_dir/drupal.9.8.2.xml"],
        FALSE,
      ],
      'enabled, normal release' => [
        CronUpdateStage::ALL,
        ['drupal' => "$fixture_dir/drupal.9.8.2.xml"],
        TRUE,
      ],
      'enabled, security release' => [
        CronUpdateStage::ALL,
        ['drupal' => "$fixture_dir/drupal.9.8.1-security.xml"],
        TRUE,
      ],
    ];
  }

  /**
   * Tests that the cron handler calls the update stage as expected.
   *
   * @param string $setting
   *   Whether automatic updates should be enabled during cron. Possible values
   *   are 'disable', 'security', and 'patch'.
   * @param array $release_data
   *   If automatic updates are enabled, the path of the fake release metadata
   *   that should be served when fetching information on available updates,
   *   keyed by project name.
   * @param bool $will_update
   *   Whether an update should be performed, given the previous two arguments.
   *
   * @dataProvider providerUpdateStageCalled
   */
  public function testUpdateStageCalled(string $setting, array $release_data, bool $will_update): void {
    $version = strpos($release_data['drupal'], '9.8.2') ? '9.8.2' : '9.8.1';
    if ($will_update) {
      $this->getStageFixtureManipulator()->setCorePackageVersion($version);
    }
    // Our form alter does not refresh information on available updates, so
    // ensure that the appropriate update data is loaded beforehand.
    $this->setReleaseMetadata($release_data);
    $this->setCoreVersion('9.8.0');
    update_get_available(TRUE);
    $this->config('automatic_updates.settings')
      ->set('unattended.level', $setting)
      ->save();

    // Since we're just trying to ensure that all of Package Manager's services
    // are called as expected, disable validation by replacing the event
    // dispatcher with a dummy version.
    $event_dispatcher = $this->prophesize(EventDispatcherInterface::class);
    $event_dispatcher->dispatch(Argument::type('object'))->willReturnArgument(0);
    $this->container->set('event_dispatcher', $event_dispatcher->reveal());

    // Run cron and ensure that Package Manager's services were called or
    // bypassed depending on configuration.
    $this->container->get('cron')->run();

    $will_update = (int) $will_update;
    $this->assertCount($will_update, $this->container->get('package_manager.beginner')->getInvocationArguments());
    // If updates happen, there will be at least two calls to the stager: one
    // to change the runtime constraints in composer.json, and another to
    // actually update the installed dependencies. If there are any core
    // dev requirements (such as `drupal/core-dev`), the stager will also be
    // called to update the dev constraints in composer.json.
    $this->assertGreaterThanOrEqual($will_update * 2, $this->container->get('package_manager.stager')->getInvocationArguments());
    $this->assertCount($will_update, $this->container->get('package_manager.committer')->getInvocationArguments());
  }

  /**
   * Data provider for testStageDestroyedOnError().
   *
   * @return string[][]
   *   The test cases.
   */
  public function providerStageDestroyedOnError(): array {
    return [
      'pre-create exception' => [
        PreCreateEvent::class,
        'Exception',
      ],
      'post-create exception' => [
        PostCreateEvent::class,
        'Exception',
      ],
      'pre-require exception' => [
        PreRequireEvent::class,
        'Exception',
      ],
      'post-require exception' => [
        PostRequireEvent::class,
        'Exception',
      ],
      'pre-apply exception' => [
        PreApplyEvent::class,
        'Exception',
      ],
      'post-apply exception' => [
        PostApplyEvent::class,
        'Exception',
      ],
      'pre-destroy exception' => [
        PreDestroyEvent::class,
        'Exception',
      ],
      'post-destroy exception' => [
        PostDestroyEvent::class,
        'Exception',
      ],
      // Only pre-operation events can add validation results.
      // @see \Drupal\package_manager\Event\PreOperationStageEvent
      // @see \Drupal\package_manager\Stage::dispatch()
      'pre-create validation error' => [
        PreCreateEvent::class,
        StageEventException::class,
      ],
      'pre-require validation error' => [
        PreRequireEvent::class,
        StageEventException::class,
      ],
      'pre-apply validation error' => [
        PreApplyEvent::class,
        StageEventException::class,
      ],
      'pre-destroy validation error' => [
        PreDestroyEvent::class,
        StageEventException::class,
      ],
    ];
  }

  /**
   * Tests that the stage is destroyed if an error occurs during a cron update.
   *
   * @param string $event_class
   *   The stage life cycle event which should raise an error.
   * @param string $exception_class
   *   The class of exception that will be thrown when the given event is fired.
   *
   * @dataProvider providerStageDestroyedOnError
   */
  public function testStageDestroyedOnError(string $event_class, string $exception_class): void {
    // If the failure happens before the stage is even created, the stage
    // fixture need not be manipulated.
    if ($event_class !== PreCreateEvent::class) {
      $this->getStageFixtureManipulator()->setCorePackageVersion('9.8.1');
    }
    $this->installConfig('automatic_updates');
    // @todo Remove in https://www.drupal.org/project/automatic_updates/issues/3284443
    $this->config('automatic_updates.settings')
      ->set('unattended.level', CronUpdateStage::SECURITY)
      ->save();
    // Ensure that there is a security release to which we should update.
    $this->setReleaseMetadata([
      'drupal' => __DIR__ . "/../../../package_manager/tests/fixtures/release-history/drupal.9.8.1-security.xml",
    ]);

    // If the pre- or post-destroy events throw an exception, it will not be
    // caught by the cron update stage, but it *will* be caught by the main cron
    // service, which will log it as a cron error that we'll want to check for.
    $cron_logger = new TestLogger();
    $this->container->get('logger.factory')
      ->get('cron')
      ->addLogger($cron_logger);

    /** @var \Drupal\automatic_updates\CronUpdateStage $stage */
    $stage = $this->container->get(CronUpdateStage::class);

    // When the event specified by $event_class is dispatched, either throw an
    // exception directly from the event subscriber, or prepare a
    // StageEventException which will format the validation errors its own way.
    if ($exception_class === StageEventException::class) {
      $error = ValidationResult::createError([
        t('Destroy the stage!'),
      ]);
      $exception = $this->createStageEventExceptionFromResults([$error], $event_class, $stage);
      TestSubscriber1::setTestResult($exception->event->getResults(), $event_class);
    }
    else {
      /** @var \Throwable $exception */
      $exception = new $exception_class('Destroy the stage!');
      TestSubscriber1::setException($exception, $event_class);
    }
    $expected_log_message = $exception->getMessage();

    // Ensure that nothing has been logged yet.
    $this->assertEmpty($cron_logger->records);
    $this->assertEmpty($this->logger->records);

    $this->assertTrue($stage->isAvailable());
    $this->startDrushUpdate('9.8.1');

    $logged_by_stage = $this->logger->hasRecord($expected_log_message, (string) RfcLogLevel::ERROR);
    // To check if the exception was logged by the main cron service, we need
    // to set up a special predicate, because exceptions logged by cron are
    // always formatted in a particular way that we don't control. But the
    // original exception object is stored with the log entry, so we look for
    // that and confirm that its message is the same.
    // @see watchdog_exception()
    $predicate = function (array $record) use ($exception): bool {
      if (isset($record['context']['exception'])) {
        return $record['context']['exception']->getMessage() === $exception->getMessage();
      }
      return FALSE;
    };
    $logged_by_cron = $cron_logger->hasRecordThatPasses($predicate, (string) RfcLogLevel::ERROR);

    // If a pre-destroy event flags a validation error, it's handled like any
    // other event (logged by the cron update stage, but not the main cron
    // service). But if a pre- or post-destroy event throws an exception, the
    // cron update stage won't try to catch it. Instead, it will be caught and
    // logged by the main cron service.
    if ($event_class === PreDestroyEvent::class || $event_class === PostDestroyEvent::class) {
      // If the pre-destroy event throws an exception or flags a validation
      // error, the stage won't be destroyed. But, once the post-destroy event
      // is fired, the stage should be fully destroyed and marked as available.
      $this->assertSame($event_class === PostDestroyEvent::class, $stage->isAvailable());
    }
    else {
      $this->assertTrue($stage->isAvailable());
    }
    $this->assertTrue($logged_by_stage);
    $this->assertFalse($logged_by_cron);
  }

  /**
   * Tests stage is destroyed if not available and site is on insecure version.
   */
  public function testStageDestroyedIfNotAvailable(): void {
    $stage = $this->createStage();
    $stage_id = $stage->create();
    $original_stage_directory = $stage->getStageDirectory();
    $this->assertDirectoryExists($original_stage_directory);

    $listener = function (PostRequireEvent $event) use (&$cron_stage_dir, $original_stage_directory): void {
      $this->assertDirectoryDoesNotExist($original_stage_directory);
      $cron_stage_dir = $this->container->get('package_manager.stager')->getInvocationArguments()[0][1]->resolve();
      $this->assertSame($event->stage->getStageDirectory(), $cron_stage_dir);
      $this->assertDirectoryExists($cron_stage_dir);
    };
    $this->addEventTestListener($listener, PostRequireEvent::class);

    $this->startDrushUpdate('9.8.1');
    $this->assertIsString($cron_stage_dir);
    $this->assertNotEquals($original_stage_directory, $cron_stage_dir);
    $this->assertDirectoryDoesNotExist($cron_stage_dir);
    $this->assertTrue($this->logger->hasRecord('The existing stage was not in the process of being applied, so it was destroyed to allow updating the site to a secure version during cron.', (string) RfcLogLevel::NOTICE));

    $stage2 = $this->createStage();
    $stage2->create();
    $this->expectException(StageOwnershipException::class);
    $this->expectExceptionMessage('The existing stage was not in the process of being applied, so it was destroyed to allow updating the site to a secure version during cron.');
    $stage->claim($stage_id);
  }

  /**
   * Tests stage is not destroyed if another update is applying.
   */
  public function testStageNotDestroyedIfApplying(): void {
    $this->config('automatic_updates.settings')
      ->set('unattended.level', CronUpdateStage::ALL)
      ->save();
    $this->setReleaseMetadata([
      'drupal' => __DIR__ . "/../../../package_manager/tests/fixtures/release-history/drupal.9.8.1-security.xml",
    ]);
    $this->setCoreVersion('9.8.0');
    $stage = $this->createStage();
    $stage->create();
    $stage->require(['drupal/core:9.8.1']);
    $stop_error = t('Stopping stage from applying');

    // Add a PreApplyEvent event listener so we can attempt to run cron when
    // another stage is applying.
    $this->addEventTestListener(function (PreApplyEvent $event) use ($stop_error) {
      // Ensure the stage that is applying the operation is not the cron
      // update stage.
      $this->assertInstanceOf(TestStage::class, $event->stage);
      $this->startDrushUpdate('9.8.1');
      // We do not actually want to apply this operation it was just invoked to
      // allow cron to be  attempted.
      $event->addError([$stop_error]);
    });

    try {
      $stage->apply();
      $this->fail('Expected update to fail');
    }
    catch (StageEventException $exception) {
      $this->assertExpectedResultsFromException([ValidationResult::createError([$stop_error])], $exception);
    }

    $this->assertTrue($this->logger->hasRecord("Cron will not perform any updates as an existing staged update is applying. The site is currently on an insecure version of Drupal core but will attempt to update to a secure version next time cron is run. This update may be applied manually at the <a href=\"%url\">update form</a>.", (string) RfcLogLevel::NOTICE));
    $this->assertUpdateStagedTimes(1);
  }

  /**
   * Tests stage is not destroyed if not available and site is on secure version.
   */
  public function testStageNotDestroyedIfSecure(): void {
    $this->config('automatic_updates.settings')
      ->set('unattended.level', CronUpdateStage::ALL)
      ->save();
    $this->setReleaseMetadata([
      'drupal' => __DIR__ . "/../../../package_manager/tests/fixtures/release-history/drupal.9.8.2.xml",
    ]);
    $this->setCoreVersion('9.8.1');
    $stage = $this->createStage();
    $stage->create();
    $stage->require(['drupal/random']);
    $this->assertUpdateStagedTimes(1);

    // Trigger CronUpdateStage, the above should cause it to detect a stage that
    // is applying.
    $this->startDrushUpdate('9.8.2');

    $this->assertTrue($this->logger->hasRecord('Cron will not perform any updates because there is an existing stage and the current version of the site is secure.', (string) RfcLogLevel::NOTICE));
    $this->assertUpdateStagedTimes(1);
  }

  /**
   * Tests that CronUpdateStage::begin() unconditionally throws an exception.
   */
  public function testBeginThrowsException(): void {
    $this->expectExceptionMessage(CronUpdateStage::class . '::begin() cannot be called directly.');
    $this->container->get(CronUpdateStage::class)
      ->begin(['drupal' => '9.8.1']);
  }

  /**
   * Tests that email is sent when an unattended update succeeds.
   */
  public function testEmailOnSuccess(): void {
    $this->getStageFixtureManipulator()->setCorePackageVersion('9.8.1');
    $this->container->get('cron')->run();
    $this->drush('auto-update');

    // Ensure we sent a success message to all recipients.
    $expected_body = <<<END
Congratulations!

Drupal core was automatically updated from 9.8.0 to 9.8.1.

This e-mail was sent by the Automatic Updates module. Unattended updates are not yet fully supported.

If you are using this feature in production, it is strongly recommended for you to visit your site and ensure that everything still looks good.
END;
    $this->assertMessagesSent("Drupal core was successfully updated", $expected_body);
    $this->assertRegularCronRun(FALSE);
  }

  /**
   * Tests that regular cron runs if not update is available.
   */
  public function testNoUpdateAvailable(): void {
    $this->setCoreVersion('9.8.2');
    $this->container->get('cron')->run();
    $this->assertRegularCronRun(TRUE);
  }

  /**
   * Tests that regular cron does not run if an update is started.
   *
   * @todo This test can be remove or simplified because cron always run first.
   *
   * @param string $event_exception_class
   *   The event in which to throw the exception.
   *
   * @dataProvider providerRegularCronRuns
   */
  public function testRegularCronRuns(string $event_exception_class): void {
    $this->addEventTestListener(
      function (): void {
        throw new \Exception('😜');
      },
      $event_exception_class
    );
    try {
      $this->container->get('cron')->run();
    }
    catch (\Exception $e) {
      if ($event_exception_class !== PostDestroyEvent::class && $event_exception_class !== PreDestroyEvent::class) {
        // No other events should result in an exception.
        throw $e;
      }
      $this->assertSame('😜', $e->getMessage());
    }
    $this->assertRegularCronRun($event_exception_class === PreCreateEvent::class);
  }

  /**
   * Data provider for testStageDestroyedOnError().
   *
   * @return string[][]
   *   The test cases.
   */
  public function providerRegularCronRuns(): array {
    return [
      'pre-create exception' => [PreCreateEvent::class],
      'post-create exception' => [PostCreateEvent::class],
      'pre-require exception' => [PreRequireEvent::class],
      'post-require exception' => [PostRequireEvent::class],
      'pre-apply exception' => [PreApplyEvent::class],
      'post-apply exception' => [PostApplyEvent::class],
      'pre-destroy exception' => [PreDestroyEvent::class],
      'post-destroy exception' => [PostDestroyEvent::class],
    ];
  }

  /**
   * Data provider for ::testEmailOnFailure().
   *
   * @return string[][]
   *   The test cases.
   */
  public function providerEmailOnFailure(): array {
    return [
      'pre-create' => [
        PreCreateEvent::class,
      ],
      'pre-require' => [
        PreRequireEvent::class,
      ],
      'pre-apply' => [
        PreApplyEvent::class,
      ],
    ];
  }

  /**
   * Tests the failure e-mail when an unattended non-security update fails.
   *
   * @param string $event_class
   *   The event class that should trigger the failure.
   *
   * @dataProvider providerEmailOnFailure
   */
  public function testNonUrgentFailureEmail(string $event_class): void {
    // If the failure happens before the stage is even created, the stage
    // fixture need not be manipulated.
    if ($event_class !== PreCreateEvent::class) {
      $this->getStageFixtureManipulator()->setCorePackageVersion('9.8.2');
    }
    $this->setReleaseMetadata([
      'drupal' => __DIR__ . '/../../../package_manager/tests/fixtures/release-history/drupal.9.8.2.xml',
    ]);
    $this->config('automatic_updates.settings')
      ->set('unattended.level', CronUpdateStage::ALL)
      ->save();

    $error = ValidationResult::createError([
      t('Error while updating!'),
    ]);
    $exception = $this->createStageEventExceptionFromResults([$error], $event_class, $this->container->get(CronUpdateStage::class));
    TestSubscriber1::setTestResult($exception->event->getResults(), $event_class);

    $this->startDrushUpdate('9.8.2');

    $url = Url::fromRoute('update.report_update')
      ->setAbsolute()
      ->toString();

    $expected_body = <<<END
Drupal core failed to update automatically from 9.8.0 to 9.8.2. The following error was logged:

{$exception->getMessage()}

No immediate action is needed, but it is recommended that you visit $url to perform the update, or at least check that everything still looks good.

This e-mail was sent by the Automatic Updates module. Unattended updates are not yet fully supported.

If you are using this feature in production, it is strongly recommended for you to visit your site and ensure that everything still looks good.
END;
    $this->assertMessagesSent("Drupal core update failed", $expected_body);
  }

  /**
   * Tests the failure e-mail when an unattended security update fails.
   *
   * @param string $event_class
   *   The event class that should trigger the failure.
   *
   * @dataProvider providerEmailOnFailure
   */
  public function testSecurityUpdateFailureEmail(string $event_class): void {
    // If the failure happens before the stage is even created, the stage
    // fixture need not be manipulated.
    if ($event_class !== PreCreateEvent::class) {
      $this->getStageFixtureManipulator()->setCorePackageVersion('9.8.1');
    }

    $error = ValidationResult::createError([
      t('Error while updating!'),
    ]);
    TestSubscriber1::setTestResult([$error], $event_class);
    $exception = $this->createStageEventExceptionFromResults([$error], $event_class, $this->container->get(CronUpdateStage::class));

    $this->startDrushUpdate('9.8.1');

    $url = Url::fromRoute('update.report_update')
      ->setAbsolute()
      ->toString();

    $expected_body = <<<END
Drupal core failed to update automatically from 9.8.0 to 9.8.1. The following error was logged:

{$exception->getMessage()}

Your site is running an insecure version of Drupal and should be updated as soon as possible. Visit $url to perform the update.

This e-mail was sent by the Automatic Updates module. Unattended updates are not yet fully supported.

If you are using this feature in production, it is strongly recommended for you to visit your site and ensure that everything still looks good.
END;
    $this->assertMessagesSent("URGENT: Drupal core update failed", $expected_body);
  }

  /**
   * Tests the failure e-mail when an unattended update fails to apply.
   */
  public function testApplyFailureEmail(): void {
    $this->getStageFixtureManipulator()->setCorePackageVersion('9.8.1');
    $error = new \LogicException('I drink your milkshake!');
    LoggingCommitter::setException($error);

    $this->startDrushUpdate('9.8.1');
    $expected_body = <<<END
Drupal core failed to update automatically from 9.8.0 to 9.8.1. The following error was logged:

Automatic updates failed to apply, and the site is in an indeterminate state. Consider restoring the code and database from a backup. Caused by LogicException, with this message: {$error->getMessage()}

This e-mail was sent by the Automatic Updates module. Unattended updates are not yet fully supported.

If you are using this feature in production, it is strongly recommended for you to visit your site and ensure that everything still looks good.
END;
    $this->assertMessagesSent('URGENT: Drupal core update failed', $expected_body);
  }

  /**
   * Tests that setLogger is called on the cron update stage service.
   */
  public function testLoggerIsSetByContainer(): void {
    $stage_method_calls = $this->container->getDefinition('automatic_updates.cron_update_stage')->getMethodCalls();
    $this->assertSame('setLogger', $stage_method_calls[0][0]);
  }

  private function assertRegularCronRun(bool $expected_cron_run) {
    $this->assertSame($expected_cron_run, $this->container->get('state')->get('common_test.cron') === 'success');
  }

  /**
   * @return void
   * @throws \Exception
   */
  protected function startDrushUpdate(string $expected_version): void {
    $drush_stage = $this->container->get(DrushUpdateStage::class);
    $release = $drush_stage->getTargetRelease();
    $this->assertInstanceOf(ProjectRelease::class, $release);
    $this->assertSame($expected_version, $release->getVersion());
    $drush_stage->performUpdate($release->getVersion(), 300);
  }

}
