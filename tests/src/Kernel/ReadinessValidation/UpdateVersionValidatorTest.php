<?php

namespace Drupal\Tests\automatic_updates\Kernel\ReadinessValidation;

use Drupal\automatic_updates\Validation\ValidationResult;
use Drupal\Tests\automatic_updates\Kernel\AutomaticUpdatesKernelTestBase;

/**
 * @covers \Drupal\automatic_updates\Validator\UpdateVersionValidator
 *
 * @group automatic_updates
 */
class UpdateVersionValidatorTest extends AutomaticUpdatesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'automatic_updates',
    'package_manager',
  ];

  /**
   * Tests an update version that is same major & minor version as the current.
   */
  public function testNoMajorOrMinorUpdates(): void {
    $this->assertCheckerResultsFromManager([], TRUE);
  }

  /**
   * Tests an update version that is a different major version than the current.
   */
  public function testMajorUpdates(): void {
    $this->setCoreVersion('8.9.1');
    $result = ValidationResult::createError(['Updating from one major version to another is not supported.']);
    $this->assertCheckerResultsFromManager([$result], TRUE);

  }

  /**
   * Tests an update version that is a different minor version than the current.
   */
  public function testMinorUpdates(): void {
    $this->setCoreVersion('9.7.1');
    $result = ValidationResult::createError(['Updating from one minor version to another is not supported.']);
    $this->assertCheckerResultsFromManager([$result], TRUE);
  }

  /**
   * Tests an update version that is a lower version than the current.
   */
  public function testDowngrading(): void {
    $this->setCoreVersion('9.8.2');
    $result = ValidationResult::createError(['Update version 9.8.1 is lower than 9.8.2, downgrading is not supported.']);
    $this->assertCheckerResultsFromManager([$result], TRUE);
  }

}
