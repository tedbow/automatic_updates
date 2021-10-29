<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessValidation;

use Drupal\automatic_updates\Event\ReadinessCheckEvent;
use Drupal\package_manager\EventSubscriber\StageValidatorInterface;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;
use Prophecy\Argument;

/**
 * Tests that the Composer executable is validated during readiness checking.
 *
 * @group automatic_updates
 *
 * @see \Drupal\Tests\package_manager\Kernel\ComposerExecutableValidatorTest
 */
class ComposerExecutableValidatorTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'automatic_updates',
    'package_manager',
  ];

  /**
   * Tests that the Composer executable is validated during readiness checking.
   */
  public function testComposerExecutableIsValidated(): void {
    // Set up a mocked version of the Composer executable validator, to prove
    // that it gets called with a readiness check event, when we run readiness
    // checks.
    $event = Argument::type(ReadinessCheckEvent::class);
    $validator = $this->prophesize(StageValidatorInterface::class);
    $validator->validateStage($event)->shouldBeCalled();
    $this->container->set('package_manager.validator.composer_executable', $validator->reveal());

    $this->container->get('automatic_updates.readiness_validation_manager')
      ->run();
  }

}
