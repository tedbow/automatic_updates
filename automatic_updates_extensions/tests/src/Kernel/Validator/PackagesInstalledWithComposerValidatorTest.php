<?php

namespace Drupal\Tests\automatic_updates_extensions\Kernel\Validator;

use Drupal\package_manager\Event\PreApplyEvent;
use Drupal\package_manager\ValidationResult;
use Drupal\Tests\automatic_updates_extensions\Kernel\AutomaticUpdatesExtensionsKernelTestBase;

/**
 * Validates the installed packages via composer after an update.
 *
 * @coversDefaultClass \Drupal\automatic_updates_extensions\Validator\PackagesInstalledWithComposerValidator
 *
 * @group automatic_updates_extensions
 */
class PackagesInstalledWithComposerValidatorTest extends AutomaticUpdatesExtensionsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // In this test, we don't care whether the updated projects are secure and
    // supported.
    $this->disableValidators[] = 'automatic_updates_extensions.validator.target_release';
    parent::setUp();
  }

  /**
   * Data provider for testPreApplyException().
   *
   * @return mixed[][]
   *   The test cases.
   */
  public function providerPreApplyException(): array {
    $summary = t('Automatic Updates can only update projects that were installed via Composer. The following packages are not installed through composer:');
    $fixtures_folder = __DIR__ . '/../../../fixtures/packages_installed_with_composer_validator';

    return [
      'module not installed via Composer' => [
        "$fixtures_folder/module_not_installed_stage",
        [
          ValidationResult::createError(['new_module'], $summary),
        ],
      ],
      'theme not installed via Composer' => [
        "$fixtures_folder/theme_not_installed_stage",
        [
          ValidationResult::createError(['new_theme'], $summary),
        ],
      ],
      'profile not installed via Composer' => [
        "$fixtures_folder/profile_not_installed_stage",
        [
          ValidationResult::createError(['new_profile'], $summary),
        ],
      ],
      // The `drupal/new_dependency` package won't show up in the error because
      // its type is `drupal-library`, and the validator only considers the
      // `drupal-module`, `drupal-theme`, and `drupal-profile` package types.
      // The `not-drupal/new_module1` package won't show up either, even though
      // its type is `drupal-module`, because it doesn't start with `drupal/`.
      // @see \Drupal\automatic_updates_extensions\Validator\PackagesInstalledWithComposerValidator
      'module, theme, and profile not installed via Composer' => [
        "$fixtures_folder/module_theme_profile_dependency_not_installed_stage",
        [
          ValidationResult::createError(
            ['new_module', 'new_theme', 'new_profile'],
            $summary
          ),
        ],
      ],
    ];
  }

  /**
   * Tests the packages installed with composer during pre-apply.
   *
   * @param string $stage_dir
   *   Path of fixture stage directory. It will be used as the virtual project's
   *   stage directory.
   * @param array $expected_results
   *   The expected validation results.
   *
   * @dataProvider providerPreApplyException
   */
  public function testPreApplyException(string $stage_dir, array $expected_results): void {
    $active_dir = __DIR__ . '/../../../fixtures/packages_installed_with_composer_validator/active';
    $this->useComposerFixturesFiles($active_dir, $stage_dir);
    $this->assertUpdateResults(['my_module' => '9.8.1'], $expected_results, PreApplyEvent::class);
  }

}
