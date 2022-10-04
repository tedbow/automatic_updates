<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessValidation;

use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\package_manager\Validator\PreOperationStageValidatorInterface;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;
use Prophecy\Argument;

/**
 * Tests that Package Manager validators are invoked during readiness checking.
 *
 * @group automatic_updates
 *
 * @covers \Drupal\automatic_updates\Validator\PackageManagerReadinessCheck
 *
 * @see \Drupal\Tests\package_manager\Kernel\ComposerExecutableValidatorTest
 * @see \Drupal\Tests\package_manager\Kernel\DiskSpaceValidatorTest
 * @see \Drupal\Tests\package_manager\Kernel\PendingUpdatesValidatorTest
 * @see \Drupal\Tests\package_manager\Kernel\WritableFileSystemValidatorTest
 */
class PackageManagerReadinessChecksTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'automatic_updates',
    'package_manager',
  ];

  /**
   * {@inheritdoc}
   */
  protected $disableValidators = [
    // The parent class disables one of the validators that we're testing, so
    // prevent that with an empty array.
  ];

  /**
   * Data provider for testValidatorInvoked().
   *
   * @return string[][]
   *   The test cases.
   */
  public function providerValidatorInvoked(): array {
    return [
      'Composer executable validator' => ['package_manager.validator.composer_executable'],
      'Disk space validator' => ['package_manager.validator.disk_space'],
      'Pending updates validator' => ['package_manager.validator.pending_updates'],
      'File system validator' => ['package_manager.validator.file_system'],
      'Composer settings validator' => ['package_manager.validator.composer_settings'],
      'Multisite validator' => ['package_manager.validator.multisite'],
      'Symlink validator' => ['package_manager.validator.symlink'],
      'Settings validator' => ['package_manager.validator.settings'],
      'Patches validator' => ['package_manager.validator.patches'],
      'Environment support validator' => ['package_manager.validator.environment_support'],
    ];
  }

  /**
   * Tests that a Package Manager validator is invoked during readiness checks.
   *
   * @param string $service_id
   *   The service ID of the validator that should be invoked.
   *
   * @dataProvider providerValidatorInvoked
   */
  public function testValidatorInvoked(string $service_id): void {
    // Set up a mocked version of the Composer executable validator, to prove
    // that it gets called with a readiness check event, when we run readiness
    // checks.
    $event = Argument::type(ReadinessCheckEvent::class);
    $validator = $this->prophesize(PreOperationStageValidatorInterface::class);
    $validator->validateStagePreOperation($event)->shouldBeCalled();
    $this->container->set($service_id, $validator->reveal());

    $this->container->get('automatic_updates.readiness_validation_manager')
      ->run();
  }

}
