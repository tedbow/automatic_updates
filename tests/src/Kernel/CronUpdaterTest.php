<?php

namespace Drupal\Tests\automatic_updates\Kernel;

use Drupal\automatic_updates\CronUpdater;
use Drupal\automatic_updates_test\EventSubscriber\TestSubscriber1;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Form\FormState;
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
use Drupal\package_manager\Exception\StageValidationException;
use Drupal\package_manager\ValidationResult;
use Drupal\package_manager_bypass\Committer;
use Drupal\Tests\automatic_updates\Traits\EmailNotificationsTestTrait;
use Drupal\Tests\package_manager\Traits\PackageManagerBypassTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
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
  ];

  /**
   * The test logger.
   *
   * @var \Psr\Log\Test\TestLogger
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
   * Data provider for testUpdaterCalled().
   *
   * @return mixed[][]
   *   The test cases.
   */
  public function providerUpdaterCalled(): array {
    $fixture_dir = __DIR__ . '/../../../package_manager/tests/fixtures/release-history';

    return [
      'disabled, normal release' => [
        CronUpdater::DISABLED,
        ['drupal' => "$fixture_dir/drupal.9.8.2.xml"],
        FALSE,
      ],
      'disabled, security release' => [
        CronUpdater::DISABLED,
        ['drupal' => "$fixture_dir/drupal.9.8.1-security.xml"],
        FALSE,
      ],
      'security only, security release' => [
        CronUpdater::SECURITY,
        ['drupal' => "$fixture_dir/drupal.9.8.1-security.xml"],
        TRUE,
      ],
      'security only, normal release' => [
        CronUpdater::SECURITY,
        ['drupal' => "$fixture_dir/drupal.9.8.2.xml"],
        FALSE,
      ],
      'enabled, normal release' => [
        CronUpdater::ALL,
        ['drupal' => "$fixture_dir/drupal.9.8.2.xml"],
        TRUE,
      ],
      'enabled, security release' => [
        CronUpdater::ALL,
        ['drupal' => "$fixture_dir/drupal.9.8.1-security.xml"],
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
   * @param array $release_data
   *   If automatic updates are enabled, the path of the fake release metadata
   *   that should be served when fetching information on available updates,
   *   keyed by project name.
   * @param bool $will_update
   *   Whether an update should be performed, given the previous two arguments.
   *
   * @dataProvider providerUpdaterCalled
   */
  public function testUpdaterCalled(string $setting, array $release_data, bool $will_update): void {
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
        StageValidationException::class,
      ],
      'pre-require validation error' => [
        PreRequireEvent::class,
        StageValidationException::class,
      ],
      'pre-apply validation error' => [
        PreApplyEvent::class,
        StageValidationException::class,
      ],
      'pre-destroy validation error' => [
        PreDestroyEvent::class,
        StageValidationException::class,
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
    $this->installConfig('automatic_updates');
    $this->setCoreVersion('9.8.0');
    // Ensure that there is a security release to which we should update.
    $this->setReleaseMetadata([
      'drupal' => __DIR__ . "/../../../package_manager/tests/fixtures/release-history/drupal.9.8.1-security.xml",
    ]);

    // Disable the symlink validator so that this test isn't affected by
    // symlinks that might be present in the running code base.
    $validator = $this->container->get('package_manager.validator.symlink');
    $this->container->get('event_dispatcher')->removeSubscriber($validator);

    // If the pre- or post-destroy events throw an exception, it will not be
    // caught by the cron updater, but it *will* be caught by the main cron
    // service, which will log it as a cron error that we'll want to check for.
    $cron_logger = new TestLogger();
    $this->container->get('logger.factory')
      ->get('cron')
      ->addLogger($cron_logger);

    // When the event specified by $event_class is fired, either throw an
    // exception directly from the event subscriber, or set a validation error
    // (if the exception class is StageValidationException).
    if ($exception_class === StageValidationException::class) {
      $results = [
        ValidationResult::createError(['Destroy the stage!']),
      ];
      TestSubscriber1::setTestResult($results, $event_class);
      $exception = new StageValidationException($results);
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

    /** @var \Drupal\automatic_updates\CronUpdater $updater */
    $updater = $this->container->get('automatic_updates.cron_updater');
    $this->assertTrue($updater->isAvailable());
    $this->container->get('cron')->run();

    $logged_by_updater = $this->logger->hasRecord($expected_log_message, RfcLogLevel::ERROR);
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
    $logged_by_cron = $cron_logger->hasRecordThatPasses($predicate, RfcLogLevel::ERROR);

    // If a pre-destroy event flags a validation error, it's handled like any
    // other event (logged by the cron updater, but not the main cron service).
    // But if a pre- or post-destroy event throws an exception, the cron updater
    // won't try to catch it. Instead, it will be caught and logged by the main
    // cron service.
    if ($event_class === PreDestroyEvent::class || $event_class === PostDestroyEvent::class) {
      if ($exception instanceof StageValidationException) {
        $this->assertTrue($logged_by_updater);
        $this->assertFalse($logged_by_cron);
      }
      else {
        $this->assertFalse($logged_by_updater);
        $this->assertTrue($logged_by_cron);
      }
      // If the pre-destroy event throws an exception or flags a validation
      // error, the stage won't be destroyed. But, once the post-destroy event
      // is fired, the stage should be fully destroyed and marked as available.
      $this->assertSame($event_class === PostDestroyEvent::class, $updater->isAvailable());
    }
    // For all other events, the error should be caught and logged by the cron
    // updater, not the main cron service, and the stage should always be
    // destroyed and marked as available.
    else {
      $this->assertTrue($logged_by_updater);
      $this->assertFalse($logged_by_cron);
      $this->assertTrue($updater->isAvailable());
    }
  }

  /**
   * Tests that CronUpdater::begin() unconditionally throws an exception.
   */
  public function testBeginThrowsException(): void {
    $this->expectExceptionMessage(CronUpdater::class . '::begin() cannot be called directly.');
    $this->container->get('automatic_updates.cron_updater')
      ->begin(['drupal' => '9.8.1']);
  }

  /**
   * Tests that email is sent when an unattended update succeeds.
   */
  public function testEmailOnSuccess(): void {
    $this->container->get('cron')->run();

    // Ensure we sent a success message to all recipients.
    $expected_body = <<<END
Congratulations!

Drupal core was automatically updated from 9.8.0 to 9.8.1.

This e-mail was sent by the Automatic Updates module. Unattended updates are not yet fully supported.

If you are using this feature in production, it is strongly recommended for you to visit your site and ensure that everything still looks good.
END;
    $this->assertMessagesSent("Drupal core was successfully updated", $expected_body);
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
    $this->setReleaseMetadata([
      'drupal' => __DIR__ . '/../../../package_manager/tests/fixtures/release-history/drupal.9.8.2.xml',
    ]);
    $this->config('automatic_updates.settings')
      ->set('cron', CronUpdater::ALL)
      ->save();

    $results = [
      ValidationResult::createError(['Error while updating!']),
    ];
    TestSubscriber1::setTestResult($results, $event_class);
    $exception = new StageValidationException($results);
    $this->container->get('cron')->run();

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
    $results = [
      ValidationResult::createError(['Error while updating!']),
    ];
    TestSubscriber1::setTestResult($results, $event_class);
    $exception = new StageValidationException($results);
    $this->container->get('cron')->run();

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
    $error = new \Exception('I drink your milkshake!');
    Committer::setException($error);

    $this->container->get('cron')->run();
    $expected_body = <<<END
Drupal core failed to update automatically from 9.8.0 to 9.8.1. The following error was logged:

The update operation failed to apply completely. All the files necessary to run Drupal correctly and securely are probably not present. It is strongly recommended to restore your site's code and database from a backup.

This e-mail was sent by the Automatic Updates module. Unattended updates are not yet fully supported.

If you are using this feature in production, it is strongly recommended for you to visit your site and ensure that everything still looks good.
END;
    $this->assertMessagesSent('URGENT: Drupal core update failed', $expected_body);
  }

  /**
   * Tests that setLogger is called on the cron updater service.
   */
  public function testLoggerIsSetByContainer(): void {
    $updater_method_calls = $this->container->getDefinition('automatic_updates.cron_updater')->getMethodCalls();
    $this->assertSame('setLogger', $updater_method_calls[0][0]);
  }

}
