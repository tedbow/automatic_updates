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
   * Data provider for testComposerSettingsValidation().
   *
   * @return mixed[][]
   *   The test cases.
   */
  public function providerComposerSettingsValidation(): array {
    $summary = t("Composer settings don't satisfy Package Manager's requirements.");

    $secure_http_error = ValidationResult::createError([
      t('HTTPS must be enabled for Composer downloads. See <a href="https://getcomposer.org/doc/06-config.md#secure-http">the Composer documentation</a> for more information.'),
    ], $summary);
    $tls_error = ValidationResult::createError([
      t('TLS must be enabled for HTTPS Composer downloads. See <a href="https://getcomposer.org/doc/06-config.md#disable-tls">the Composer documentation</a> for more information.'),
      t('You should also check the value of <code>secure-http</code> and make sure that it is set to <code>true</code> or not set at all.'),
    ], $summary);

    return [
      'secure-http set to FALSE' => [
        [
          'secure-http' => FALSE,
        ],
        [$secure_http_error],
      ],
      'secure-http explicitly set to TRUE' => [
        [
          'secure-http' => TRUE,
        ],
        [],
      ],
      'secure-http implicitly set to TRUE' => [
        [
          'extra.unrelated' => TRUE,
        ],
        [],
      ],
      'disable-tls set to TRUE' => [
        [
          'disable-tls' => TRUE,
        ],
        [$tls_error],
      ],
      'disable-tls implicitly set to FALSE' => [
        [
          'extra.unrelated' => TRUE,
        ],
        [],
      ],
      'explicitly set disable-tls to FALSE' => [
        [
          'disable-tls' => FALSE,
        ],
        [],
      ],
      'disable-tls set to TRUE + secure-http set to TRUE, message only for TLS, secure-http overridden' => [
        [
          'disable-tls' => TRUE,
          'secure-http' => TRUE,
        ],
        [$tls_error],
      ],
      'disable-tls set to TRUE + secure-http set to FALSE, message only for TLS' => [
        [
          'disable-tls' => TRUE,
          'secure-http' => FALSE,
        ],
        [$tls_error],
      ],
    ];
  }

  /**
   * Tests that Composer's settings are validated.
   *
   * @param array $config
   *   The config to set.
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results, if any.
   *
   * @dataProvider providerComposerSettingsValidation
   */
  public function testComposerSettingsValidation(array $config, array $expected_results): void {
    (new ActiveFixtureManipulator())->addConfig($config)->commitChanges();
    $this->assertStatusCheckResults($expected_results);
    $this->assertResults($expected_results, PreCreateEvent::class);
  }

  /**
   * Tests that Composer's settings are validated during pre-apply.
   *
   * @param array $config
   *   The config to set.
   * @param \Drupal\package_manager\ValidationResult[] $expected_results
   *   The expected validation results, if any.
   *
   * @dataProvider providerComposerSettingsValidation
   */
  public function testComposerSettingsValidationDuringPreApply(array $config, array $expected_results): void {
    $this->getStageFixtureManipulator()->addConfig($config);
    $this->assertResults($expected_results, PreApplyEvent::class);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Ensure that any warnings arising from Composer settings (which we expect
    // in this test) will not fail the test during tear-down.
    $this->failureLogger->reset();
    parent::tearDown();
  }

}
