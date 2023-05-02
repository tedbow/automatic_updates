<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\package_manager\ValidationResult;

/**
 * @covers \Drupal\package_manager\Validator\PhpExtensionsValidator
 * @group package_manager
 * @internal
 */
class PhpExtensionsValidatorTest extends PackageManagerKernelTestBase {

  /**
   * Data provider for ::testPhpExtensionsValidation().
   *
   * @return array[]
   *   The test cases.
   */
  public function providerPhpExtensionsValidation(): array {
    return [
      'xdebug enabled, openssl installed' => [
        ['xdebug', 'openssl'],
        [
          ValidationResult::createWarning([
            t('Xdebug is enabled, which may have a negative performance impact on Package Manager and any modules that use it.'),
          ]),
        ],
      ],
      'xdebug enabled, openssl not installed' => [
        ['xdebug'],
        [
          ValidationResult::createWarning([
            t('Xdebug is enabled, which may have a negative performance impact on Package Manager and any modules that use it.'),
          ]),
          ValidationResult::createWarning([
            t('The OpenSSL extension is not enabled, which is a security risk. See <a href="https://www.php.net/manual/en/openssl.installation.php">the PHP documentation</a> for information on how to enable this extension.'),
          ]),
        ],
      ],
      'xdebug disabled, openssl installed' => [
        ['openssl'],
        [],
      ],
      'xdebug disabled, openssl not installed' => [
        [],
        [
          ValidationResult::createWarning([
            t('The OpenSSL extension is not enabled, which is a security risk. See <a href="https://www.php.net/manual/en/openssl.installation.php">the PHP documentation</a> for information on how to enable this extension.'),
          ]),
        ],
      ],
    ];
  }

  /**
   * Tests that PHP extensions' status are checked by Package Manager.
   *
   * @param string[] $loaded_extensions
   *   The names of the PHP extensions that the validator should think are
   *   loaded.
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results.
   *
   * @dataProvider providerPhpExtensionsValidation
   */
  public function testPhpExtensionsValidation(array $loaded_extensions, array $expected_results): void {
    // @see \Drupal\package_manager\Validator\PhpExtensionsValidator::isExtensionLoaded()
    $this->container->get('state')
      ->set('package_manager_loaded_php_extensions', $loaded_extensions);

    $this->assertStatusCheckResults($expected_results);
  }

}
