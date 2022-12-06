<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\package_manager\ValidationResult;

/**
 * @covers \Drupal\package_manager\Validator\XdebugValidator
 * @group package_manager
 * @internal
 */
class XdebugValidatorTest extends PackageManagerKernelTestBase {

  /**
   * Tests warnings and/or errors if Xdebug is enabled.
   */
  public function testXdebugValidation(): void {
    // Ensure the validator will think Xdebug is enabled.
    if (!function_exists('xdebug_break')) {
      // @codingStandardsIgnoreLine
      eval('function xdebug_break() {}');
    }

    $result = ValidationResult::createWarning([
      'Xdebug is enabled, which may have a negative performance impact on Package Manager and any modules that use it.',
    ]);
    $this->assertStatusCheckResults([$result]);
  }

}
