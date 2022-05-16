<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessValidation;

use Drupal\Core\Logger\RfcLogLevel;
use Drupal\package_manager\Exception\StageValidationException;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;
use Drupal\Tests\package_manager\Traits\PackageManagerBypassTestTrait;
use Psr\Log\Test\TestLogger;

/**
 * @covers \Drupal\automatic_updates\Validator\VersionPolicyValidator
 *
 * @group automatic_updates
 */
class VersionPolicyValidatorTest extends AutomaticUpdatesKernelTestBase {

  use PackageManagerBypassTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates'];

  /**
   * Data provider for ::testAttended().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerAttended(): array {
    return [];
  }

  /**
   * Tests version policy for attended updates.
   *
   * @param string $installed_version
   *   The installed version of Drupal core, as known to the update system.
   * @param string[] $release_metadata
   *   The paths of the XML release metadata files to use, keyed by project
   *   name.
   * @param string $cron_setting
   *   The setting for cron updates. Should be one of the constants from
   *   \Drupal\automatic_updates\CronUpdater.
   * @param string[] $project_versions
   *   The versions of the projects to update, keyed by name.
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results, if any.
   *
   * @dataProvider providerAttended
   *
   * @see parent::setReleaseMetadata()
   */
  public function testAttended(string $installed_version, array $release_metadata, string $cron_setting, array $project_versions, array $expected_results): void {
    $this->setCoreVersion($installed_version);
    $this->setReleaseMetadata($release_metadata);
    $this->config('automatic_updates.settings')
      ->set('cron', $cron_setting)
      ->save();

    $this->assertCheckerResultsFromManager($expected_results, TRUE);

    try {
      $this->container->get('automatic_updates.updater')
        ->begin($project_versions);
      $this->assertEmpty($expected_results);
      $this->assertUpdateStagedTimes(1);
    }
    catch (StageValidationException $e) {
      $this->assertValidationResultsEqual($expected_results, $e->getResults());
      $this->assertUpdateStagedTimes(0);
    }
  }

  /**
   * Data provider for ::testUnattended().
   *
   * @return array[]
   *   Sets of arguments to pass to the test method.
   */
  public function providerUnattended(): array {
    return [];
  }

  /**
   * Tests version policy for unattended updates.
   *
   * @param string $installed_version
   *   The installed version of Drupal core, as known to the update system.
   * @param string[] $release_metadata
   *   The paths of the XML release metadata files to use, keyed by project
   *   name.
   * @param string $cron_setting
   *   The setting for cron updates. Should be one of the constants from
   *   \Drupal\automatic_updates\CronUpdater.
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results, if any.
   *
   * @dataProvider providerUnattended
   *
   * @see parent::setReleaseMetadata()
   */
  public function testUnattended(string $installed_version, array $release_metadata, string $cron_setting, array $expected_results): void {
    $this->setCoreVersion($installed_version);
    $this->setReleaseMetadata($release_metadata);
    $this->config('automatic_updates.settings')
      ->set('cron', $cron_setting)
      ->save();

    $logger = new TestLogger();
    $this->container->get('logger.factory')
      ->get('automatic_updates')
      ->addLogger($logger);

    $this->assertCheckerResultsFromManager($expected_results, TRUE);
    $this->container->get('cron')->run();

    if ($expected_results) {
      $this->assertUpdateStagedTimes(0);
      $e = new StageValidationException($expected_results);
      $this->assertTrue($logger->hasRecordThatContains($e->getMessage(), RfcLogLevel::ERROR));
    }
    else {
      $this->assertUpdateStagedTimes(1);
      $this->assertTrue($logger->hasRecordThatContains("Drupal core has been updated from $installed_version to ", RfcLogLevel::INFO));
    }
  }

}
