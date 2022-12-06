<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\Event\StatusCheckEvent;
use Drupal\package_manager\ValidationResult;
use Drupal\package_manager\Validator\EnvironmentSupportValidator;

/**
 * @covers \Drupal\package_manager\Validator\EnvironmentSupportValidator
 * @group package_manager
 * @internal
 */
class EnvironmentSupportValidatorTest extends PackageManagerKernelTestBase {

  /**
   * Tests handling of an invalid URL in the environment support variable.
   */
  public function testInvalidUrl(): void {
    putenv(EnvironmentSupportValidator::VARIABLE_NAME . '=broken/url.org');

    $result = ValidationResult::createError([
      'Package Manager is not supported by your environment.',
    ]);
    foreach ([PreCreateEvent::class, StatusCheckEvent::class] as $event_class) {
      $this->container->get('event_dispatcher')->addListener(
        $event_class,
        function () use ($event_class): void {
          $this->fail('Event propagation should have been stopped during ' . $event_class . '.');
        },
        // Execute this listener immediately after the tested validator, which
        // uses priority 200. This ensures informative test failures.
        // @see \Drupal\package_manager\Validator\EnvironmentSupportValidator::getSubscribedEvents()
        199
      );
    }
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
