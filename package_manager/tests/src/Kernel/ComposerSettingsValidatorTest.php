<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\package_manager\Exception\StageValidationException;
use Drupal\package_manager\ValidationResult;

/**
 * @covers \Drupal\package_manager\Validator\ComposerSettingsValidator
 *
 * @group package_manager
 */
class ComposerSettingsValidatorTest extends PackageManagerKernelTestBase {

  /**
   * Data provider for testSecureHttpValidation().
   *
   * @return mixed[][]
   *   The test cases.
   */
  public function providerSecureHttpValidation(): array {
    $error = ValidationResult::createError([
      'HTTPS must be enabled for Composer downloads. See <a href="https://getcomposer.org/doc/06-config.md#secure-http">the Composer documentation</a> for more information.',
    ]);

    return [
      'disabled' => [
        Json::encode([
          'config' => [
            'secure-http' => FALSE,
          ],
        ]),
        [$error],
      ],
      'explicitly enabled' => [
        Json::encode([
          'config' => [
            'secure-http' => TRUE,
          ],
        ]),
        [],
      ],
      'implicitly enabled' => [
        '{}',
        [],
      ],
    ];
  }

  /**
   * Tests that Composer's secure-http setting is validated.
   *
   * @param string $contents
   *   The contents of the composer.json file.
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results, if any.
   *
   * @dataProvider providerSecureHttpValidation
   */
  public function testSecureHttpValidation(string $contents, array $expected_results): void {
    $active_dir = $this->container->get('package_manager.path_locator')
      ->getProjectRoot();
    file_put_contents("$active_dir/composer.json", $contents);

    try {
      $this->createStage()->create();
      $this->assertSame([], $expected_results);
    }
    catch (StageValidationException $e) {
      $this->assertValidationResultsEqual($expected_results, $e->getResults());
    }
  }

}
