<?php

namespace Drupal\Tests\package_manager\Build;

/**
 * Tests updating packages in a staging area.
 *
 * @group package_manager
 */
class PackageUpdateTest extends TemplateProjectTestBase {

  /**
   * Tests updating packages in a staging area.
   */
  public function testPackageUpdate(): void {
    $this->createTestProject('RecommendedProject');

    $this->addRepository('alpha', __DIR__ . '/../../fixtures/alpha/1.0.0');
    $this->addRepository('updated_module', __DIR__ . '/../../fixtures/updated_module/1.0.0');
    $this->runComposer('COMPOSER_MIRROR_PATH_REPOS=1 composer require drupal/alpha drupal/updated_module --update-with-all-dependencies', 'project');

    $this->installQuickStart('minimal');
    $this->formLogin($this->adminUsername, $this->adminPassword);
    // The updated_module provides actual Drupal-facing functionality that we're
    // testing as well, so we need to install it.
    $this->installModules(['package_manager_test_api', 'updated_module']);

    // Change both modules' upstream version.
    $this->addRepository('alpha', __DIR__ . '/../../fixtures/alpha/1.1.0');
    $this->addRepository('updated_module', __DIR__ . '/../../fixtures/updated_module/1.1.0');

    // Use the API endpoint to create a stage and update updated_module to
    // 1.1.0. Even though both modules have version 1.1.0 available, only
    // updated_module should be updated. We ask the API to return the contents
    // of both modules' composer.json files, so we can assert that they were
    // updated to the versions we expect.
    // @see \Drupal\package_manager_test_api\ApiController::run()
    $query = http_build_query([
      'runtime' => [
        'drupal/updated_module:1.1.0',
      ],
      'files_to_return' => [
        'web/modules/contrib/alpha/composer.json',
        'web/modules/contrib/updated_module/composer.json',
        'bravo.txt',
        "system_changes.json",
      ],
    ]);
    $this->visit("/package-manager-test-api?$query");
    $mink = $this->getMink();
    $mink->assertSession()->statusCodeEquals(200);

    $file_contents = $mink->getSession()->getPage()->getContent();
    $file_contents = json_decode($file_contents, TRUE);

    $expected_versions = [
      'alpha' => '1.0.0',
      'updated_module' => '1.1.0',
    ];
    foreach ($expected_versions as $module_name => $expected_version) {
      $path = "web/modules/contrib/$module_name/composer.json";
      $module_composer_json = json_decode($file_contents[$path]);
      $this->assertSame($expected_version, $module_composer_json->version);
    }
    // The post-apply event subscriber in updated_module 1.1.0 should have
    // created this file.
    // @see \Drupal\updated_module\PostApplySubscriber::postApply()
    $this->assertSame('Bravo!', $file_contents['bravo.txt']);

    $results = json_decode($file_contents['system_changes.json'], TRUE);
    $expected_pre_apply_results = [
      'return value of existing global function' => 'pre-update-value',
      'new global function exists' => 'not exists',
      'path of changed route' => '/updated-module/changed/pre',
      'deleted route exists' => 'exists',
      'new route exists' => 'not exists',
      'title of changed permission' => 'permission',
      'deleted permission exists' => 'exists',
      'new permission exists' => 'not exists',
      'updated_module.existing_service exists' => 'exists',
      'value of updated_module.existing_service' => 'Pre-update value',
      'updated_module.deleted_service exists' => 'exists',
      'value of updated_module.deleted_service' => 'Deleted service, should not exist after update',
      'updated_module.added_service exists' => 'not exists',
      'updated_module_deleted_block block exists' => 'exists',
      'updated_module_deleted_block block label' => 'Deleted block',
      'updated_module_deleted_block block output' => 'Goodbye!',
      'updated_module_updated_block block exists' => 'exists',
      'updated_module_updated_block block label' => '1.0.0',
      'updated_module_updated_block block output' => '1.0.0',
      'updated_module_added_block block exists' => 'not exists',
      // This block is not instantiated until the update is done.
      'updated_module_ignored_block block exists' => 'exists',
      'updated_module_ignored_block block label' => '1.0.0',
      'ChangedClass exists' => 'exists',
      'value of ChangedClass' => 'Before Update',
      'LoadedAndDeletedClass exists' => 'exists',
      'value of LoadedAndDeletedClass' => 'This class will be loaded and then deleted',
    ];
    $this->assertSame($expected_pre_apply_results, $results['pre']);

    $expected_post_apply_results = [
      // Existing functions will still use the pre-update version.
      'return value of existing global function' => 'pre-update-value',
      // New functions that were added in .module files will not be available.
      'new global function exists' => 'not exists',
      // Definitions for existing routes should be updated.
      'path of changed route' => '/updated-module/changed/post',
      // Routes deleted from the updated module should not be available.
      'deleted route exists' => 'not exists',
      // Routes added to the updated module should be available.
      'new route exists' => 'exists',
      // Title of the existing permission should be changed.
      'title of changed permission' => 'changed permission',
      // Permissions deleted from the updated module should not be available.
      'deleted permission exists' => 'not exists',
      // Permissions added to the updated module should be available.
      'new permission exists' => 'exists',
      // The existing generic service should have a new string value.
      'updated_module.existing_service exists' => 'exists',
      'value of updated_module.existing_service' => 'Post-update value',
      // Services deleted from the updated module should not be available.
      'updated_module.deleted_service exists' => 'not exists',
      // Services added to the updated module should be available and return
      // the expected value.
      'updated_module.added_service exists' => 'exists',
      'value of updated_module.added_service' => 'New service, should not exist before update',
      // A block removed from the updated module should not be defined anymore.
      'updated_module_deleted_block block exists' => 'not exists',
      // A block that was updated should have a changed definition, but an
      // unchanged implementation.
      'updated_module_updated_block block exists' => 'exists',
      'updated_module_updated_block block label' => '1.1.0',
      'updated_module_updated_block block output' => '1.0.0',
      // A block added to the module should be defined.
      'updated_module_added_block block exists' => 'exists',
      'updated_module_added_block block label' => 'Added block',
      'updated_module_added_block block output' => 'Hello!',
      // A block whose definition and implementation were updated, but was NOT
      // instantiated before the update, should have an updated definition and
      // implementation.
      'updated_module_ignored_block block exists' => 'exists',
      'updated_module_ignored_block block label' => '1.1.0',
      'updated_module_ignored_block block output' => 'I was ignored before the update.',
      // Existing class should be available.
      'ChangedClass exists' => 'exists',
      // Existing class will still use the pre-update version.
      'value of ChangedClass' => 'Before Update',
      // Classes loaded in pre-apply or before and deleted from the updated module should
      // be available.
      'LoadedAndDeletedClass exists' => 'exists',
      'value of LoadedAndDeletedClass' => 'This class will be loaded and then deleted',
      // Classes not loaded before the apply operation and deleted from the updated module
      // should not be available.
      'DeletedClass exists' => 'not exists',
      // Classes added to the updated module should be available.
      'AddedClass exists' => 'exists',
      'value of AddedClass' => 'This class will be added',
    ];
    $this->assertSame($expected_post_apply_results, $results['post']);
  }

}
