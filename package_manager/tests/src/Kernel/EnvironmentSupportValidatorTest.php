<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\package_manager\Event\PreApplyEvent;
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
      t('Package Manager is not supported by your environment.'),
    ]);
    foreach ([PreCreateEvent::class, StatusCheckEvent::class] as $event_class) {
      $this->assertEventPropagationStopped($event_class, [$this->container->get('package_manager.validator.environment_support'), 'validate']);
    }
    $this->assertStatusCheckResults([$result]);
    $this->assertResults([$result], PreCreateEvent::class);
  }

  /**
   * Tests an invalid URL in the environment support variable during pre-apply.
   */
  public function testInvalidUrlDuringPreApply(): void {
    $this->addEventTestListener(function (): void {
      putenv(EnvironmentSupportValidator::VARIABLE_NAME . '=broken/url.org');
    });

    $result = ValidationResult::createError([
      'Package Manager is not supported by your environment.',
    ]);

    $this->assertEventPropagationStopped(PreApplyEvent::class, [$this->container->get('package_manager.validator.environment_support'), 'validate']);
    $this->assertResults([$result], PreApplyEvent::class);
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

  /**
   * Tests that the validation message links to the provided URL during pre-apply.
   */
  public function testValidUrlDuringPreApply(): void {
    $url = 'http://www.example.com';
    $this->addEventTestListener(function () use ($url): void {
      putenv(EnvironmentSupportValidator::VARIABLE_NAME . '=' . $url);
    });

    $result = ValidationResult::createError([
      '<a href="' . $url . '">Package Manager is not supported by your environment.</a>',
    ]);
    $this->assertResults([$result], PreApplyEvent::class);
  }

}
