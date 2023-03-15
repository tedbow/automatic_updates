<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\fixture_manipulator\ActiveFixtureManipulator;
use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\ValidationResult;

/**
 * @covers \Drupal\package_manager\Validator\ComposerSettingsValidator
 * @group package_manager
 * @internal
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
      t('HTTPS must be enabled for Composer downloads. See <a href="https://getcomposer.org/doc/06-config.md#secure-http">the Composer documentation</a> for more information.'),
    ]);

    return [
      'disabled' => [
        [
          'secure-http' => FALSE,
        ],
        [$error],
      ],
      'explicitly enabled' => [
        [
          'secure-http' => TRUE,
        ],
        [],
      ],
      'implicitly enabled' => [
        [
          'extra.unrelated' => TRUE,
        ],
        [],
      ],
    ];
  }

  /**
   * Tests that Composer's secure-http setting is validated.
   *
   * @param array $config
   *   The config to set.
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results, if any.
   *
   * @dataProvider providerSecureHttpValidation
   */
  public function testSecureHttpValidation(array $config, array $expected_results): void {
    (new ActiveFixtureManipulator())->addConfig($config)->commitChanges();
    $this->assertStatusCheckResults($expected_results);
    $this->assertResults($expected_results, PreCreateEvent::class);
  }

  /**
   * Tests that Composer's secure-http setting is validated during pre-apply.
   *
   * @param array $config
   *   The config to set.
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results, if any.
   *
   * @dataProvider providerSecureHttpValidation
   */
  public function testSecureHttpValidationDuringPreApply(array $config, array $expected_results): void {
    $this->getStageFixtureManipulator()->addConfig($config);
    $this->assertResults($expected_results, PreApplyEvent::class);
  }

}
