<?php

namespace Drupal\Tests\automatic_updates_extensions\Kernel\Validator;

use Drupal\package_manager\Event\PreCreateEvent;
use Drupal\package_manager\ValidationResult;
use Drupal\Tests\automatic_updates_extensions\Kernel\AutomaticUpdatesExtensionsKernelTestBase;

/**
 * Validates the type of updated packages.
 *
 * @coversDefaultClass \Drupal\automatic_updates_extensions\Validator\UpdatePackagesTypeValidator
 *
 * @group automatic_updates_extensions
 */
class UpdatePackagesTypeValidatorTest extends AutomaticUpdatesExtensionsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // In this test, we don't focus on validating that the updated projects are
    // secure and supported. Therefore, we need to disable the update release
    // validator that validates updated projects are secure and supported.
    $this->disableValidators[] = 'automatic_updates_extensions.validator.target_release';
    $this->disableValidators[] = 'automatic_updates_extensions.validator.packages_installed_with_composer';
    parent::setUp();
  }

  /**
   * Data provider for testUpdatePackagesAreOnlyThemesOrModules().
   *
   * @return mixed[][]
   *   The test cases.
   */
  public function providerUpdatePackagesAreOnlyThemesOrModules(): array {
    return [
      'non existing project updated' => [
        [
          'non_existing_project' => '9.8.1',
        ],
        [ValidationResult::createError(['non_existing_project'], t('Only Drupal Modules or Drupal Themes can be updated, therefore the following projects cannot be updated:'))],
      ],
      'non existing project, test module and test theme updated' => [
        [
          'non_existing_project' => '9.8.1',
          'test_module_project' => '9.8.1',
          'test_theme_project' => '9.8.1',
        ],
        [ValidationResult::createError(['non_existing_project'], t('Only Drupal Modules or Drupal Themes can be updated, therefore the following projects cannot be updated:'))],
      ],
      'drupal updated' => [
        [
          'drupal' => '9.8.1',
        ],
        [ValidationResult::createError(['drupal'], t('Only Drupal Modules or Drupal Themes can be updated, therefore the following projects cannot be updated:'))],
      ],
    ];
  }

  /**
   * Tests the packages installed with composer during pre-create.
   *
   * @param array $projects
   *   The projects to install.
   * @param array $expected_results
   *   The expected validation results.
   *
   * @dataProvider providerUpdatePackagesAreOnlyThemesOrModules
   */
  public function testUpdatePackagesAreOnlyThemesOrModules(array $projects, array $expected_results): void {
    $module_info = ['project' => 'test_module_project'];
    $this->config('update_test.settings')
      ->set("system_info.aaa_automatic_updates_test", $module_info)
      ->save();
    $theme_info = ['project' => 'test_theme_project'];
    $this->config('update_test.settings')
      ->set("system_info.automatic_updates_theme", $theme_info)
      ->save();
    $this->assertUpdateResults($projects, $expected_results, PreCreateEvent::class);
  }

}
