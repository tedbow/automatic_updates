<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Unit;

use Drupal\package_manager\Stage;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\package_manager\Stage
 * @group package_manager
 * @internal
 */
class StageTest extends UnitTestCase {

  /**
   * @covers ::validateRequirements
   *
   * @param string|null $expected_exception
   *   The exception class that should be thrown, or NULL if there should not be
   *   any exception.
   * @param string $requirement
   *   The requirement (package name and optional constraint) to validate.
   *
   * @dataProvider providerValidateRequirements
   */
  public function testValidateRequirements(?string $expected_exception, string $requirement): void {
    $reflector = new \ReflectionClass(Stage::class);
    $method = $reflector->getMethod('validateRequirements');
    $method->setAccessible(TRUE);

    if ($expected_exception) {
      $this->expectException($expected_exception);
    }
    else {
      $this->assertNull($expected_exception);
    }

    $method->invoke(NULL, [$requirement]);
  }

  /**
   * Data provider for testValidateRequirements.
   *
   * @return array[]
   *   The test cases.
   */
  public function providerValidateRequirements(): array {
    return [
      // Valid requirements.
      [NULL, 'vendor/package'],
      [NULL, 'vendor/snake_case'],
      [NULL, 'vendor/kebab-case'],
      [NULL, 'vendor/with.dots'],
      [NULL, '1vendor2/3package4'],
      [NULL, 'vendor/package:1'],
      [NULL, 'vendor/package:1.2'],
      [NULL, 'vendor/package:1.2.3'],
      [NULL, 'vendor/package:1.x'],
      [NULL, 'vendor/package:^1'],
      [NULL, 'vendor/package:~1'],
      [NULL, 'vendor/package:>1'],
      [NULL, 'vendor/package:<1'],
      [NULL, 'vendor/package:>=1'],
      [NULL, 'vendor/package:>1 <2'],
      [NULL, 'vendor/package:1 || 2'],
      [NULL, 'vendor/package:>=1,<1.1.0'],
      [NULL, 'vendor/package:1a'],
      [NULL, 'vendor/package:*'],
      [NULL, 'vendor/package:dev-master'],
      [NULL, 'vendor/package:*@dev'],
      [NULL, 'vendor/package:@dev'],
      [NULL, 'vendor/package:master@dev'],
      [NULL, 'vendor/package:master@beta'],
      [NULL, 'php'],
      [NULL, 'php:8'],
      [NULL, 'php:8.0'],
      [NULL, 'php:^8.1'],
      [NULL, 'php:~8.1'],
      [NULL, 'php-64bit'],
      [NULL, 'composer'],
      [NULL, 'composer-plugin-api'],
      [NULL, 'composer-plugin-api:1'],
      [NULL, 'ext-json'],
      [NULL, 'ext-json:1'],
      [NULL, 'ext-pdo_mysql'],
      [NULL, 'ext-pdo_mysql:1'],
      [NULL, 'lib-curl'],
      [NULL, 'lib-curl:1'],
      [NULL, 'lib-curl-zlib'],
      [NULL, 'lib-curl-zlib:1'],

      // Invalid requirements.
      [\InvalidArgumentException::class, ''],
      [\InvalidArgumentException::class, ' '],
      [\InvalidArgumentException::class, '/'],
      [\InvalidArgumentException::class, 'php8'],
      [\InvalidArgumentException::class, 'package'],
      [\InvalidArgumentException::class, 'vendor\package'],
      [\InvalidArgumentException::class, 'vendor//package'],
      [\InvalidArgumentException::class, 'vendor/package1 vendor/package2'],
      [\InvalidArgumentException::class, 'vendor/package/extra'],
      [\UnexpectedValueException::class, 'vendor/package:a'],
      [\UnexpectedValueException::class, 'vendor/package:'],
      [\UnexpectedValueException::class, 'vendor/package::'],
      [\UnexpectedValueException::class, 'vendor/package::1'],
      [\UnexpectedValueException::class, 'vendor/package:1:2'],
      [\UnexpectedValueException::class, 'vendor/package:develop@dev@dev'],
      [\UnexpectedValueException::class, 'vendor/package:develop@'],
      [\InvalidArgumentException::class, 'vEnDor/pAcKaGe'],
      [\InvalidArgumentException::class, '_vendor/package'],
      [\InvalidArgumentException::class, '_vendor/_package'],
      [\InvalidArgumentException::class, 'vendor_/package'],
      [\InvalidArgumentException::class, '_vendor/package_'],
      [\InvalidArgumentException::class, 'vendor/package-'],
      [\InvalidArgumentException::class, 'php-'],
      [\InvalidArgumentException::class, 'ext'],
      [\InvalidArgumentException::class, 'lib'],
    ];
  }

}
