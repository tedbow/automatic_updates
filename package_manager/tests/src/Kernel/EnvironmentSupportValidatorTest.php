<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\PreOperationStageEvent;
use Drupal\package_manager\ValidationResult;
use Drupal\package_manager\Validator\EnvironmentSupportValidator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * @covers \Drupal\package_manager\Validator\EnvironmentSupportValidator
 *
 * @group package_manager
 */
class EnvironmentSupportValidatorTest extends PackageManagerKernelTestBase implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('event_dispatcher')->addSubscriber($this);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $map = function (): string {
      return 'assertValidationStopped';
    };
    return array_map($map, EnvironmentSupportValidator::getSubscribedEvents());
  }

  /**
   * Ensures that the validator stops any further validation.
   *
   * @param \Drupal\package_manager\Event\PreOperationStageEvent $event
   *   The event object.
   */
  public function assertValidationStopped(PreOperationStageEvent $event): void {
    if ($event->getResults()) {
      $this->assertTrue($event->isPropagationStopped());
    }
  }

  /**
   * Tests handling of an invalid URL in the environment support variable.
   */
  public function testInvalidUrl(): void {
    putenv(EnvironmentSupportValidator::VARIABLE_NAME . '=broken/url.org');

    $result = ValidationResult::createError([
      'Package Manager is not supported by your environment.',
    ]);
    $this->assertStatusCheckResults([$result]);
    $this->assertResults([$result], PreCreateEvent::class);
  }

  /**
   * Tests that the validation message links to the provided URL.
   */
  public function testValidUrl(): void {
    $url = 'http://www.example.com';
    putenv(EnvironmentSupportValidator::VARIABLE_NAME . '=' . $url);

    $result = ValidationResult::createError([
      '<a href="' . $url . '">Package Manager is not supported by your environment.</a>',
    ]);
    $this->assertStatusCheckResults([$result]);
    $this->assertResults([$result], PreCreateEvent::class);
  }

}
