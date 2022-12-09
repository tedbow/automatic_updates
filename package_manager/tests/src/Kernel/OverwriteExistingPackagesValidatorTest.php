<?php

declare(strict_types = 1);

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\package_manager\Exception\StageValidationException;
use Drupal\package_manager\ValidationResult;
use Drupal\Tests\package_manager\Traits\FixtureUtilityTrait;

/**
 * @covers \Drupal\package_manager\Validator\OverwriteExistingPackagesValidator
 * @group package_manager
 * @internal
 */
class OverwriteExistingPackagesValidatorTest extends PackageManagerKernelTestBase {

  use FixtureUtilityTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // In this test, we don't care whether the updated projects are secure and
    // supported.
    $this->disableValidators[] = 'package_manager.validator.supported_releases';
    parent::setUp();
  }

  /**
   * Tests that new installed packages overwrite existing directories.
   *
   * The fixture simulates a scenario where the active directory has three
   * modules installed: module_1, module_2, and module_5. None of them are
   * managed by Composer. These modules will be moved into the staging directory
   * by the 'package_manager_bypass' module.
   */
  public function testNewPackagesOverwriteExisting(): void {
    $active_dir = $this->container->get('package_manager.path_locator')->getProjectRoot();
    $modules_dir = "$active_dir/modules";
    $this->addProjectAtPath("$modules_dir/module_1");
    $this->addProjectAtPath("$modules_dir/module_2");
    $this->addProjectAtPath("$modules_dir/module_5");
    $stage = $this->createStage();
    $stage->create();
    $stage_dir = $stage->getStageDirectory();

    // module_1 and module_2 will raise errors because they would overwrite
    // non-Composer managed paths in the active directory.
    $this->addPackage(
      $stage_dir,
      [
        'name' => 'drupal/module_1',
        'version' => '1.3.0',
        'type' => 'drupal-module',
        'install_path' => '../../modules/module_1',
      ],
      FALSE,
      FALSE
    );
    $this->addPackage(
      $stage_dir,
      [
        'name' => 'drupal/module_2',
        'version' => '1.3.0',
        'type' => 'drupal-module',
        'install_path' => '../../modules/module_2',
      ],
      FALSE,
      FALSE
    );

    // module_3 will cause no problems, since it doesn't exist in the active
    // directory at all.
    $this->addPackage($stage_dir, [
      'name' => 'drupal/module_3',
      'version' => '1.3.0',
      'type' => 'drupal-module',
      'install_path' => '../../modules/module_3',
    ]);

    // module_4 doesn't exist in the active directory but the 'install_path' as
    // known to Composer in the staged directory collides with module_1 in the
    // active directory which will cause an error.
    $this->addPackage(
      $stage_dir,
      [
        'name' => 'drupal/module_4',
        'version' => '1.3.0',
        'type' => 'drupal-module',
        'install_path' => '../../modules/module_1',
      ],
      FALSE,
      FALSE,
    );

    // module_5_different_path will not cause a problem, even though its package
    // name is drupal/module_5, because its project name and path in the staging
    // area differ from the active directory.
    $this->addPackage($stage_dir, [
      'name' => 'drupal/module_5',
      'version' => '1.3.0',
      'type' => 'drupal-module',
      'install_path' => '../../modules/module_5_different_path',
    ]);

    // Add a package without an install_path set which will not raise an error.
    // The most common example of this in the Drupal ecosystem is a submodule.
    $this->addPackage($stage_dir, [
      'name' => 'drupal/sub-module',
      'version' => '1.3.0',
      'type' => 'metapackage',
    ]);

    $expected_results = [
      ValidationResult::createError([
        'The new package drupal/module_1 will be installed in the directory /vendor/composer/../../modules/module_1, which already exists but is not managed by Composer.',
      ]),
      ValidationResult::createError([
        'The new package drupal/module_2 will be installed in the directory /vendor/composer/../../modules/module_2, which already exists but is not managed by Composer.',
      ]),
      ValidationResult::createError([
        'The new package drupal/module_4 will be installed in the directory /vendor/composer/../../modules/module_1, which already exists but is not managed by Composer.',
      ]),
    ];

    $stage->require(['drupal/core:9.8.1']);
    try {
      $stage->apply();
      // If no exception occurs, ensure we weren't expecting any errors.
      $this->assertValidationResultsEqual($expected_results, []);
    }
    catch (StageValidationException $e) {
      $this->assertNotEmpty($expected_results);
      $this->assertValidationResultsEqual($expected_results, $e->getResults());
    }
  }

}
