<?php

namespace Drupal\Tests\package_manager\Kernel;

use Drupal\package_manager\Exception\StageValidationException;
use Drupal\package_manager\ValidationResult;

/**
 * @covers \Drupal\package_manager\Validator\OverwriteExistingPackagesValidator
 *
 * @group package_manager
 */
class OverwriteExistingPackagesValidatorTest extends PackageManagerKernelTestBase {

  /**
   * Tests that new installed packages overwrite existing directories.
   *
   * The fixture simulates a scenario where the active directory has three
   * modules installed: module_1, module_2, and module_5. None of them are
   * managed by Composer.
   *
   * The staging area has four modules: module_1, module_2, module_3, and
   * module_5_different_path. All of them are managed by Composer. We expect the
   * following outcomes:
   *
   * - module_1 and module_2 will raise errors because they would overwrite
   *   non-Composer managed paths in the active directory.
   * - module_3 will cause no problems, since it doesn't exist in the active
   *   directory at all.
   * - module_4, which is defined only in the staged installed.json and
   *   installed.php, will cause an error because its path collides with
   *   module_1.
   * - module_5_different_path will not cause a problem, even though its package
   *   name is drupal/module_5, because its project name and path in the staging
   *   area differ from the active directory.
   */
  public function testNewPackagesOverwriteExisting(): void {
    $fixtures_dir = __DIR__ . '/../../fixtures/overwrite_existing_validation';
    $this->copyFixtureFolderToActiveDirectory("$fixtures_dir/active");
    $this->copyFixtureFolderToStageDirectoryOnApply("$fixtures_dir/staged");

    $stage = $this->createStage();
    $stage->create();
    $stage->require(['drupal' => '9.8.1']);

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

    try {
      $stage->apply();
      // If no exception occurs, ensure we weren't expecting any errors.
      $this->assertEmpty($expected_results);
    }
    catch (StageValidationException $e) {
      $this->assertNotEmpty($expected_results);
      $this->assertValidationResultsEqual($expected_results, $e->getResults());
    }
  }

}
