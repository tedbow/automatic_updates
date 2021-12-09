<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessValidation;

use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\package_manager\EventSubscriber\PreOperationStageValidatorInterface;
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
  protected function disableValidators(ContainerBuilder $container): void {
    // No need to disable any validators in this test.
  }

  /**
   * Data provider for ::testValidatorInvoked().
   *
   * @return string[][]
   *   Sets of arguments to pass to the test method.
   */
  public function providerValidatorInvoked(): array {
    return [
      ['package_manager.validator.composer_executable'],
      ['package_manager.validator.disk_space'],
      ['package_manager.validator.pending_updates'],
      ['package_manager.validator.file_system'],
      ['package_manager.validator.composer_settings'],
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
