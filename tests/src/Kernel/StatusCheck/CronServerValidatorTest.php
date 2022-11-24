<?php

namespace Drupal\Tests\automatic_updates\Kernel\StatusCheck;

use Drupal\automatic_updates\CronUpdater;
use Drupal\automatic_updates\Validator\CronServerValidator;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Url;
use Drupal\package_manager\Exception\StageValidationException;
use Drupal\package_manager\ValidationResult;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;
use ColinODell\PsrTestLogger\TestLogger;

/**
 * @covers \Drupal\automatic_updates\Validator\CronServerValidator
 * @group automatic_updates
 * @internal
 */
class CronServerValidatorTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates'];

  /**
   * Data provider for ::testCronServerValidation().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerCronServerValidation(): array {
    $error = ValidationResult::createError([
      'Your site appears to be running on the built-in PHP web server on port 80. Drupal cannot be automatically updated with this configuration unless the site can also be reached on an alternate port.',
    ]);

    return [
      'PHP server with alternate port' => [
        TRUE,
        'cli-server',
        [CronUpdater::DISABLED, CronUpdater::SECURITY, CronUpdater::ALL],
        [],
      ],
      'PHP server with same port, cron enabled' => [
        FALSE,
        'cli-server',
        [CronUpdater::SECURITY, CronUpdater::ALL],
        [$error],
      ],
      'PHP server with same port, cron disabled' => [
        FALSE,
        'cli-server',
        [CronUpdater::DISABLED],
        [],
      ],
      'other server with alternate port' => [
        TRUE,
        'nginx',
        [CronUpdater::DISABLED, CronUpdater::SECURITY, CronUpdater::ALL],
        [],
      ],
      'other server with same port' => [
        FALSE,
        'nginx',
        [CronUpdater::DISABLED, CronUpdater::SECURITY, CronUpdater::ALL],
        [],
      ],
    ];
  }

  /**
   * Tests server validation for unattended updates.
   *
   * @param bool $alternate_port
   *   Whether or not an alternate port should be set.
   * @param string $server_api
   *   The value of the PHP_SAPI constant, as known to the validator.
   * @param string[] $cron_modes
   *   The cron modes to test with. Can contain any of
   *   \Drupal\automatic_updates\CronUpdater::DISABLED,
   *   \Drupal\automatic_updates\CronUpdater::SECURITY, and
   *   \Drupal\automatic_updates\CronUpdater::ALL.
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results.
   *
   * @dataProvider providerCronServerValidation
   */
  public function testCronServerValidation(bool $alternate_port, string $server_api, array $cron_modes, array $expected_results): void {
    $request = $this->container->get('request_stack')->getCurrentRequest();
    $this->assertNotEmpty($request);
    $this->assertSame(80, $request->getPort());

    $property = new \ReflectionProperty(CronServerValidator::class, 'serverApi');
    $property->setAccessible(TRUE);
    $property->setValue(NULL, $server_api);

    foreach ($cron_modes as $mode) {
      $this->config('automatic_updates.settings')
        ->set('cron', $mode)
        ->set('cron_port', $alternate_port ? 2501 : 0)
        ->save();

      $this->assertCheckerResultsFromManager($expected_results, TRUE);

      $logger = new TestLogger();
      $this->container->get('logger.factory')
        ->get('automatic_updates')
        ->addLogger($logger);

      // If errors were expected, cron should not have run.
      $this->container->get('cron')->run();
      if ($expected_results) {
        $error = new StageValidationException($expected_results);
        $this->assertTrue($logger->hasRecord($error->getMessage(), RfcLogLevel::ERROR));
      }
      else {
        $this->assertFalse($logger->hasRecords(RfcLogLevel::ERROR));
      }
    }
  }

  /**
   * Tests server validation for unattended updates with Help enabled.
   *
   * @param bool $alternate_port
   *   Whether or not an alternate port should be set.
   * @param string $server_api
   *   The value of the PHP_SAPI constant, as known to the validator.
   * @param string[] $cron_modes
   *   The cron modes to test with. Can contain any of
   *   \Drupal\automatic_updates\CronUpdater::DISABLED,
   *   \Drupal\automatic_updates\CronUpdater::SECURITY, and
   *   \Drupal\automatic_updates\CronUpdater::ALL.
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results.
   *
   * @dataProvider providerCronServerValidation
   */
  public function testHelpLink(bool $alternate_port, string $server_api, array $cron_modes, array $expected_results): void {
    $this->enableModules(['help']);

    $url = Url::fromRoute('help.page')
      ->setRouteParameter('name', 'automatic_updates')
      ->setOption('fragment', 'cron-alternate-port')
      ->toString();

    foreach ($expected_results as $i => $result) {
      $messages = [];
      foreach ($result->getMessages() as $message) {
        $messages[] = "$message See <a href=\"$url\">the Automatic Updates help page</a> for more information on how to resolve this.";
      }
      $expected_results[$i] = ValidationResult::createError($messages);
    }
    $this->testCronServerValidation($alternate_port, $server_api, $cron_modes, $expected_results);
  }

}
